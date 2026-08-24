<?php

declare(strict_types=1);

namespace Davix\Customs\Provider\Hmrc;

/**
 * Read access to a JSON:API document, with the sideloaded resources indexed.
 *
 * The tariff sideloads heavily: a single commodity arrives with 651 entries in
 * `included`, spanning twenty resource types. Those are referenced from
 * relationships by `(type, id)` pairs, and ids repeat across types — a
 * geographical area and a measure type can both be "103" and mean entirely
 * different things. Indexing on the pair rather than the id alone is not
 * fussiness; keying on id would silently mix them.
 *
 * Everything here tolerates absence. Upstream shapes shift, and a missing
 * relationship should degrade one field rather than fail a whole scan.
 */
final class JsonApiDocument
{
    /** @var array<string, array<string, array<string, mixed>>> type => id => resource */
    private array $included = [];

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        private readonly array $document,
    ) {
        $included = $document['included'] ?? [];

        if (!is_array($included)) {
            return;
        }

        foreach ($included as $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;

            if (is_string($type) && (is_string($id) || is_int($id))) {
                $this->included[$type][(string) $id] = self::stringKeyed($resource);
            }
        }
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self($decoded);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        $data = $this->document['data'] ?? null;

        return is_array($data) ? self::stringKeyed($data) : null;
    }

    /**
     * The primary resource's attributes.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return self::attributesOf($this->data());
    }

    /**
     * Attributes of any resource, string-keyed and safe when absent.
     *
     * Static because the mappers need it on sideloaded resources they hold
     * directly, without threading a document through every private method.
     *
     * @param array<string, mixed>|null $resource
     * @return array<string, mixed>
     */
    public static function attributesOf(?array $resource): array
    {
        if ($resource === null) {
            return [];
        }

        $attributes = $resource['attributes'] ?? null;

        return is_array($attributes) ? self::stringKeyed($attributes) : [];
    }

    /**
     * A value from the primary resource's `meta`, which is where the tariff
     * puts its precomputed duty calculator flags — under `data`, not at the
     * document root.
     *
     * @return array<string, mixed>
     */
    public function meta(string $key): array
    {
        $data = $this->data();
        $meta = $data['meta'] ?? [];

        if (!is_array($meta)) {
            return [];
        }

        $meta = self::stringKeyed($meta);

        $value = $meta[$key] ?? [];

        return is_array($value) ? self::stringKeyed($value) : [];
    }

    /**
     * Resolve a to-one relationship on the primary resource.
     *
     * @return array<string, mixed>|null
     */
    public function related(string $relationship): ?array
    {
        return $this->resolveOne($this->data(), $relationship);
    }

    /**
     * Resolve a to-many relationship on the primary resource.
     *
     * @return list<array<string, mixed>>
     */
    public function relatedMany(string $relationship): array
    {
        return $this->resolveMany($this->data(), $relationship);
    }

    /**
     * Resolve a to-one relationship on any resource.
     *
     * @param array<string, mixed>|null $resource
     * @return array<string, mixed>|null
     */
    public function resolveOne(?array $resource, string $relationship): ?array
    {
        $reference = $this->reference($resource, $relationship);

        if ($reference === null) {
            return null;
        }

        return $this->find($reference['type'], $reference['id']);
    }

    /**
     * Resolve a to-many relationship on any resource.
     *
     * References that cannot be resolved are dropped rather than yielding
     * nulls the caller has to filter.
     *
     * @param array<string, mixed>|null $resource
     * @return list<array<string, mixed>>
     */
    public function resolveMany(?array $resource, string $relationship): array
    {
        $data = $this->relationshipData($resource, $relationship);

        if (!is_array($data)) {
            return [];
        }

        $resolved = [];

        foreach ($data as $reference) {
            if (!is_array($reference)) {
                continue;
            }

            $type = $reference['type'] ?? null;
            $id = $reference['id'] ?? null;

            if (!is_string($type) || (!is_string($id) && !is_int($id))) {
                continue;
            }

            $found = $this->find($type, (string) $id);

            if ($found !== null) {
                $resolved[] = $found;
            }
        }

        return $resolved;
    }

    /**
     * Reference identifiers for a to-many relationship, without resolving.
     *
     * Needed for excluded countries, where the identifier is the country code
     * and the sideloaded resource adds nothing beyond it.
     *
     * @param array<string, mixed>|null $resource
     * @return list<string>
     */
    public function referenceIds(?array $resource, string $relationship): array
    {
        $data = $this->relationshipData($resource, $relationship);

        if (!is_array($data)) {
            return [];
        }

        $ids = [];

        foreach ($data as $reference) {
            if (is_array($reference) && isset($reference['id'])) {
                $id = $reference['id'];

                if (is_string($id) || is_int($id)) {
                    $ids[] = (string) $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $type, string $id): ?array
    {
        return $this->included[$type][$id] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allOfType(string $type): array
    {
        return array_values($this->included[$type] ?? []);
    }

    /**
     * @param array<string, mixed>|null $resource
     * @return array{type: string, id: string}|null
     */
    private function reference(?array $resource, string $relationship): ?array
    {
        $data = $this->relationshipData($resource, $relationship);

        if (!is_array($data)) {
            return null;
        }

        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;

        if (!is_string($type) || (!is_string($id) && !is_int($id))) {
            return null;
        }

        return ['type' => $type, 'id' => (string) $id];
    }

    /**
     * @param array<string, mixed>|null $resource
     */
    private function relationshipData(?array $resource, string $relationship): mixed
    {
        if ($resource === null) {
            return null;
        }

        $relationships = $resource['relationships'] ?? null;

        if (!is_array($relationships)) {
            return null;
        }

        $entry = $relationships[$relationship] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        return $entry['data'] ?? null;
    }

    /**
     * Keep only string keys, which is what every JSON object in this payload
     * actually has.
     *
     * json_decode produces array<mixed, mixed> as far as static analysis is
     * concerned, because a JSON array and a JSON object decode to the same PHP
     * type. Narrowing here once is cheaper than an assertion at every use.
     *
     * @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}