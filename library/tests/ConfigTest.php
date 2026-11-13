<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Config;
use Foundry\File;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-config-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        file_put_contents($this->dir.'/app.php', <<<'PHP'
            <?php
            return [
                'name' => 'Portal',
                'debug' => false,
                'version' => 3,
                'ratio' => 0.5,
                'empty' => null,
                'hosts' => ['a.ro', 'b.ro'],
                'mail' => ['smtp' => ['host' => 'smtp.local', 'port' => 587]],
            ];
            PHP);

        Config::load($this->dir);
    }

    protected function tearDown(): void
    {
        Config::reset();
        File::deleteDir($this->dir);
    }

    public function test_get_with_dot_notation(): void
    {
        $this->assertSame('Portal', Config::get('app.name'));
        $this->assertSame('smtp.local', Config::get('app.mail.smtp.host'));
        $this->assertSame(['a.ro', 'b.ro'], Config::get('app.hosts'));
        $this->assertSame('a.ro', Config::get('app.hosts.0'));
    }

    public function test_get_whole_file(): void
    {
        $this->assertIsArray(Config::get('app'));
    }

    public function test_get_null_value_is_found_not_missing(): void
    {
        $this->assertNull(Config::get('app.empty'));
        $this->assertTrue(Config::has('app.empty'));
    }

    public function test_get_missing_key_throws_without_default(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("configuration key 'app.missing' is not set");

        Config::get('app.missing');
    }

    public function test_get_missing_key_with_default(): void
    {
        $this->assertSame('implicit', Config::get('app.missing', 'implicit'));
        $this->assertNull(Config::get('app.missing', null));
        $this->assertSame('x', Config::get('lipsa.cheie', 'x'), 'fisier lipsa + default = default');
    }

    public function test_missing_file_throws_without_default(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("configuration file '{$this->dir}/lipsa.php' does not exist");

        Config::get('lipsa.cheie');
    }

    public function test_has(): void
    {
        $this->assertTrue(Config::has('app.mail.smtp'));
        $this->assertFalse(Config::has('app.mail.imap'));
        $this->assertFalse(Config::has('app.name.prea_adanc'));
        $this->assertFalse(Config::has('lipsa.cheie'));
    }

    public function test_typed_getters(): void
    {
        $this->assertSame('Portal', Config::string('app.name'));
        $this->assertSame(3, Config::int('app.version'));
        $this->assertEqualsWithDelta(0.5, Config::float('app.ratio'), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(3.0, Config::float('app.version'), PHP_FLOAT_EPSILON);
        $this->assertFalse(Config::bool('app.debug'));
        $this->assertSame(['host' => 'smtp.local', 'port' => 587], Config::array('app.mail.smtp'));
    }

    public function test_typed_getters_defaults(): void
    {
        $this->assertSame('x', Config::string('app.lipsa', 'x'));
        $this->assertSame(1, Config::int('app.lipsa', 1));
        $this->assertTrue(Config::bool('app.lipsa', true));
        $this->assertSame([], Config::array('lipsa', []));
    }

    public function test_typed_getters_do_not_convert(): void
    {
        $cases = [
            static fn (): mixed => Config::string('app.version'),
            static fn (): mixed => Config::int('app.name'),
            static fn (): mixed => Config::bool('app.version'),
            static fn (): mixed => Config::array('app.name'),
            static fn (): mixed => Config::float('app.name'),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Expected UnexpectedValueException');
            } catch (UnexpectedValueException $e) {
                $this->assertMatchesRegularExpression("/configuration key 'app\.\w+' must be an? \w+, got \w+\./", $e->getMessage());
            }
        }
    }

    public function test_typed_default_does_not_hide_wrong_type(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Config::int('app.name', 10);
    }

    public function test_file_must_return_array(): void
    {
        file_put_contents($this->dir.'/gresit.php', '<?php return "text";');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("gresit.php' must return an array, got string");

        Config::get('gresit.x');
    }

    public function test_file_name_cannot_escape_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("invalid configuration file name '/etc/passwd'");

        Config::get('/etc/passwd');
    }

    public function test_empty_segments_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Config::get('app..name');
    }

    public function test_set_overrides_in_memory(): void
    {
        Config::set('app.name', 'Alt nume');
        Config::set('app.mail.smtp.port', 25);
        Config::set('nou.cheie.adanca', true);

        $this->assertSame('Alt nume', Config::string('app.name'));
        $this->assertSame(25, Config::int('app.mail.smtp.port'));
        $this->assertSame('smtp.local', Config::string('app.mail.smtp.host'), 'restul fisierului ramane');
        $this->assertTrue(Config::bool('nou.cheie.adanca'));
    }

    public function test_set_whole_file_requires_array(): void
    {
        Config::set('nou', ['a' => 1]);
        $this->assertSame(1, Config::int('nou.a'));

        $this->expectException(InvalidArgumentException::class);

        Config::set('nou', 'text');
    }

    public function test_requires_load_first(): void
    {
        Config::reset();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('call Config::load($directory) first');

        Config::get('app.name');
    }

    public function test_load_requires_existing_directory(): void
    {
        $this->expectException(RuntimeException::class);

        Config::load($this->dir.'/lipsa');
    }

    public function test_load_clears_previous_values(): void
    {
        Config::set('app.name', 'Modificat');

        Config::load($this->dir);

        $this->assertSame('Portal', Config::string('app.name'));
    }
}
