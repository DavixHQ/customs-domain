<?php

declare(strict_types=1);

namespace Davix\Customs\Rule;

use Davix\Customs\Exception\RuleConfigurationException;
use Davix\Customs\Product\ProductCustomsDataInterface;

/**
 * Holds the rule set, orders it by dependency, and runs it against a product.
 *
 * Configuration errors surface at construction rather than mid-scan: a
 * prerequisite naming a rule that is not registered, a duplicate code, or a
 * cycle all throw immediately. Discovering a dependency cycle halfway through
 * a 50,000-product scan is not a useful time to find out.
 */
final class RulePool
{
    /** @var array<string, RuleInterface> */
    private array $rules = [];

    /** @var list<RuleInterface>|null */
    private ?array $ordered = null;

    /**
     * @param iterable<RuleInterface> $rules
     * @throws RuleConfigurationException
     */
    public function __construct(iterable $rules = [])
    {
        foreach ($rules as $rule) {
            $this->add($rule);
        }

        $this->validate();
    }

    /**
     * @throws RuleConfigurationException
     */
    public function add(RuleInterface $rule): void
    {
        $code = $rule->code();

        if (isset($this->rules[$code])) {
            throw RuleConfigurationException::duplicateCode($code);
        }

        $this->rules[$code] = $rule;
        $this->ordered = null;
    }

    public function has(string $code): bool
    {
        return isset($this->rules[$code]);
    }

    public function get(string $code): ?RuleInterface
    {
        return $this->rules[$code] ?? null;
    }

    public function count(): int
    {
        return count($this->rules);
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->rules);
    }

    /**
     * Rules in dependency order: every rule appears after its prerequisites.
     *
     * Rules with no relationship keep their registration order, so a merchant
     * reading a config screen sees a stable list.
     *
     * @return list<RuleInterface>
     */
    public function ordered(): array
    {
        if ($this->ordered !== null) {
            return $this->ordered;
        }

        /** @var array<string, true> $resolved */
        $resolved = [];
        /** @var array<string, true> $visiting */
        $visiting = [];
        /** @var list<RuleInterface> $sorted */
        $sorted = [];

        foreach (array_keys($this->rules) as $code) {
            $this->visit($code, $resolved, $visiting, $sorted, []);
        }

        return $this->ordered = $sorted;
    }

    /**
     * Run every enabled rule against one product.
     *
     * A rule whose prerequisite emitted an issue is skipped, and the skip
     * cascades to anything depending on it. A rule the merchant disabled is
     * treated as passing rather than as unknown - silencing a check should not
     * silently disable everything downstream of it.
     */
    public function evaluate(
        ProductCustomsDataInterface $data,
        EvaluationContext $context,
    ): RuleEvaluation {
        $settings = $context->settings;

        /** @var list<Issue> $issues */
        $issues = [];
        /** @var array<string, SkipReason> $skipped */
        $skipped = [];
        /** @var array<string, true> $failed */
        $failed = [];

        foreach ($this->ordered() as $rule) {
            $code = $rule->code();

            if (!$settings->isEnabled($code)) {
                $skipped[$code] = SkipReason::Disabled;
                continue;
            }

            $blocker = $this->blockingPrerequisite($rule, $failed, $skipped);

            if ($blocker !== null) {
                $skipped[$code] = $blocker;
                continue;
            }

            $issue = $rule->evaluate($data, $context);

            if ($issue === null) {
                continue;
            }

            $issues[] = $issue->withSeverity(
                $settings->severityFor($code, $issue->severity),
            );

            $failed[$code] = true;
        }

        return new RuleEvaluation($issues, $skipped);
    }

    /**
     * @param array<string, true> $failed
     * @param array<string, SkipReason> $skipped
     */
    private function blockingPrerequisite(
        RuleInterface $rule,
        array $failed,
        array $skipped,
    ): ?SkipReason {
        foreach ($rule->prerequisites() as $prerequisite) {
            if (isset($failed[$prerequisite])) {
                return SkipReason::PrerequisiteFailed;
            }

            // A disabled prerequisite counts as passing. Anything skipped for
            // another reason has an unknown result, so dependants cannot run.
            $reason = $skipped[$prerequisite] ?? null;

            if ($reason !== null && $reason !== SkipReason::Disabled) {
                return SkipReason::PrerequisiteSkipped;
            }
        }

        return null;
    }

    /**
     * @throws RuleConfigurationException
     */
    private function validate(): void
    {
        foreach ($this->rules as $code => $rule) {
            foreach ($rule->prerequisites() as $prerequisite) {
                if ($prerequisite === $code) {
                    throw RuleConfigurationException::selfPrerequisite($code);
                }

                if (!isset($this->rules[$prerequisite])) {
                    throw RuleConfigurationException::unknownPrerequisite($code, $prerequisite);
                }
            }
        }

        // Forces the topological sort, which detects cycles.
        $this->ordered();
    }

    /**
     * @param array<string, true> $resolved
     * @param array<string, true> $visiting
     * @param list<RuleInterface> $sorted
     * @param list<string> $path
     * @throws RuleConfigurationException
     */
    private function visit(
        string $code,
        array &$resolved,
        array &$visiting,
        array &$sorted,
        array $path,
    ): void {
        if (isset($resolved[$code])) {
            return;
        }

        if (isset($visiting[$code])) {
            $path[] = $code;
            throw RuleConfigurationException::circularPrerequisites($path);
        }

        $rule = $this->rules[$code] ?? null;

        if ($rule === null) {
            return;
        }

        $visiting[$code] = true;
        $path[] = $code;

        foreach ($rule->prerequisites() as $prerequisite) {
            $this->visit($prerequisite, $resolved, $visiting, $sorted, $path);
        }

        unset($visiting[$code]);
        $resolved[$code] = true;
        $sorted[] = $rule;
    }
}
