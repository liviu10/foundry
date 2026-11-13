<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use InvalidArgumentException;
use SplObjectStorage;
use Throwable;
use UnexpectedValueException;

/**
 * Serializer Class
 *
 * Secure serialize() and unserialize(). For new data, prefer Json: it is portable and
 * cannot instantiate objects. Serializer is intended for existing data (sessions, cache, legacy columns).
 *
 * Native issues resolved:
 *   - unserialize() on untrusted data can instantiate arbitrary classes (object injection); here,
 *     by default NO classes are allowed, and permitted classes must be explicitly provided;
 *   - disallowed classes silently become __PHP_Incomplete_Class; here, an exception is thrown;
 *   - invalid data or trailing data ("Extra data") only emits a warning; here, an exception is thrown;
 *   - serialize() silently converts resources (file handles, connections) to 0; here, an exception is thrown.
 *
 * Usage:
 *   require_once 'Serializer.php';
 *   use Foundry\Serializer;
 *
 *   $data = Serializer::unserialize($row['payload']);                         // scalars and arrays only
 *   $dto = Serializer::unserialize($cached, allowedClasses: [Factura::class]);  // plus permitted classes
 *   $text = Serializer::serialize($data);
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Serializer
{
    /** @var int Default maximum depth for unserialize(). */
    public const int DEPTH = 64;

    /** @var string Serialized representation of false (the only valid case where unserialize() returns false). */
    private const string SERIALIZED_FALSE = 'b:0;';

    private function __construct() {}

    /**
     * Serializes a value.
     *
     * @throws InvalidArgumentException If the value contains resources, closures, or other non-serializable values.
     */
    public static function serialize(mixed $value): string
    {
        self::assertSerializable($value, new SplObjectStorage, 'value');

        try {
            return serialize($value);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Serializer::serialize(): '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Unserializes data. By default, no classes are allowed.
     *
     * @param  string  $data  The serialized string.
     * @param  list<class-string>  $allowedClasses  Classes allowed to be instantiated.
     * @param  int  $maxDepth  Maximum depth of the data structure.
     * @return mixed The unserialized value.
     *
     * @throws UnexpectedValueException If the data is invalid, contains trailing data, or contains
     *                                  a disallowed class.
     */
    public static function unserialize(string $data, array $allowedClasses = [], int $maxDepth = self::DEPTH): mixed
    {
        [$value, $warning] = self::capture(static fn (): mixed => unserialize($data, [
            'allowed_classes' => $allowedClasses === [] ? false : $allowedClasses,
            'max_depth' => $maxDepth,
        ]));

        if ($warning !== null) {
            // Ex: "unserialize(): Error at offset 0 of 3 bytes", "Extra data starting at offset 4 of 8 bytes"
            throw new UnexpectedValueException('Serializer::unserialize(): '.preg_replace('/^unserialize\(\):\s*/', '', $warning).'.');
        }

        if ($value === false && $data !== self::SERIALIZED_FALSE) {
            throw new UnexpectedValueException(sprintf('Serializer::unserialize(): invalid serialized data (%d bytes).', strlen($data)));
        }

        $incomplete = self::findIncompleteClass($value, new SplObjectStorage);
        if ($incomplete !== null) {
            throw new UnexpectedValueException("Serializer::unserialize(): class '{$incomplete}' is not allowed; add it to \$allowedClasses if it is trusted.");
        }

        return $value;
    }

    /**
     * Recursively checks for resources and closures.
     *
     * @param  SplObjectStorage<object, mixed>  $visited  Already visited objects (prevents circular reference loops).
     *
     * @throws InvalidArgumentException On the first non-serializable value found.
     */
    private static function assertSerializable(mixed $value, SplObjectStorage $visited, string $path): void
    {
        if (is_resource($value) || get_debug_type($value) === 'resource (closed)') {
            throw new InvalidArgumentException("Serializer::serialize(): {$path} is a resource and cannot be serialized.");
        }

        if ($value instanceof Closure) {
            throw new InvalidArgumentException("Serializer::serialize(): {$path} is a Closure and cannot be serialized.");
        }

        if (is_object($value)) {
            if ($visited->contains($value)) {
                return;
            }
            $visited->attach($value);
            $value = (array) $value; // include proprietatile private/protected
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertSerializable($item, $visited, $path.'['.self::cleanKey($key).']');
            }
        }
    }

    /**
     * Returns the name of the first disallowed class (__PHP_Incomplete_Class) found in the value, or null.
     *
     * @param  SplObjectStorage<object, mixed>  $visited  Already visited objects (prevents circular reference loops).
     */
    private static function findIncompleteClass(mixed $value, SplObjectStorage $visited): ?string
    {
        if (is_object($value)) {
            if ($visited->contains($value)) {
                return null;
            }
            $visited->attach($value);

            $properties = (array) $value;
            if ($value instanceof \__PHP_Incomplete_Class) {
                $name = $properties['__PHP_Incomplete_Class_Name'] ?? 'unknown';

                return is_string($name) ? $name : 'unknown';
            }
            $value = $properties;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $found = self::findIncompleteClass($item, $visited);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Formats the property key for error messages: after (array) conversion, private/protected properties
     * take the form "\0Class\0name" or "\0*\0name"; anonymous class names also contain \0.
     * The property name is extracted after the last \0.
     */
    private static function cleanKey(int|string $key): string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        $last = strrpos($key, "\0");

        return $last === false ? $key : substr($key, $last + 1);
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute.
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private static function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
