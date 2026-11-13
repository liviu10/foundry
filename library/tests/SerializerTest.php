<?php

declare(strict_types=1);

namespace Foundry\Tests;

use ArrayObject;
use DateTimeImmutable;
use Foundry\Serializer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

final class SerializerTest extends TestCase
{
    // serialize

    public function test_round_trip_of_scalars_and_arrays(): void
    {
        $data = ['a' => 1, 'b' => [true, null, 1.5, 'text'], 'c' => false];

        $this->assertSame($data, Serializer::unserialize(Serializer::serialize($data)));
    }

    public function test_false_is_a_valid_value(): void
    {
        $this->assertFalse(Serializer::unserialize(Serializer::serialize(false)));
    }

    public function test_serialize_rejects_resources_even_when_nested(): void
    {
        $handle = fopen('php://memory', 'r');

        try {
            Serializer::serialize(['config' => ['log' => $handle]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Serializer::serialize(): value[config][log] is a resource and cannot be serialized.', $e->getMessage());
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function test_serialize_rejects_resources_in_private_properties(): void
    {
        $object = new class
        {
            /** @var resource|false */
            private $handle;

            public function __construct()
            {
                $this->handle = fopen('php://memory', 'r');
            }

            /** @return resource|false */
            public function handle()
            {
                return $this->handle;
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('value[handle] is a resource');

        Serializer::serialize($object);
    }

    public function test_serialize_rejects_closures(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('value[callback] is a Closure');

        Serializer::serialize(['callback' => static fn (): int => 1]);
    }

    public function test_serialize_handles_circular_references(): void
    {
        $a = new stdClass;
        $a->self = $a;

        $this->assertStringStartsWith('O:8:"stdClass"', Serializer::serialize($a));
    }

    // unserialize

    public function test_objects_are_rejected_by_default(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("class 'stdClass' is not allowed");

        Serializer::unserialize(serialize(new stdClass));
    }

    public function test_nested_disallowed_class_is_detected(): void
    {
        $data = serialize(['ok' => 1, 'items' => [new ArrayObject([1])]]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("class 'ArrayObject' is not allowed");

        Serializer::unserialize($data);
    }

    public function test_allowed_classes(): void
    {
        $date = new DateTimeImmutable('2026-10-30 10:00:00');

        $result = Serializer::unserialize(serialize(['data' => $date]), allowedClasses: [DateTimeImmutable::class]);

        $this->assertIsArray($result);
        $this->assertEquals($date, $result['data']);
    }

    public function test_class_not_in_allowed_list_is_rejected(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("class 'stdClass' is not allowed");

        Serializer::unserialize(serialize([new DateTimeImmutable, new stdClass]), allowedClasses: [DateTimeImmutable::class]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDataProvider(): iterable
    {
        yield 'sintaxa invalida' => ['x:1', 'Error at offset 0 of 3 bytes'];
        yield 'trunchiat' => ['a:2:{i:0;i:1;', 'Error at offset'];
        yield 'continut in plus' => ['i:1;junk', 'Extra data starting at offset 4 of 8 bytes'];
        yield 'gol' => ['', 'invalid serialized data (0 bytes)'];
    }

    #[DataProvider('invalidDataProvider')]
    public function test_invalid_data(string $data, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        Serializer::unserialize($data);
    }

    public function test_max_depth(): void
    {
        $deep = serialize([[[[1]]]]);

        $this->assertSame([[[[1]]]], Serializer::unserialize($deep, maxDepth: 4));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('max_depth');

        Serializer::unserialize($deep, maxDepth: 2);
    }

    public function test_warnings_are_not_leaked_to_host_error_handler(): void
    {
        $leaked = [];
        set_error_handler(static function (int $severity, string $message) use (&$leaked): bool {
            $leaked[] = $message;

            return true;
        });

        try {
            Serializer::unserialize('i:1;junk');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException) {
            // asteptat
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $leaked);
    }
}
