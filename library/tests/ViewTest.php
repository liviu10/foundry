<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\View;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use UnexpectedValueException;

final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/view-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/polite', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->dir);
    }

    public function test_render_with_variables_and_escaping(): void
    {
        $this->template('salut', 'Salut, <?= $e($nume) ?>! <?= $e($titlu) ?>');
        $view = new View($this->dir, shared: ['titlu' => 'Aplicatie']);

        $this->assertSame('Salut, &lt;b&gt;Ion &amp; &quot;Co&quot;&lt;/b&gt;! Aplicatie', $view->render('salut', ['nume' => '<b>Ion & "Co"</b>']));
    }

    public function test_data_overrides_shared(): void
    {
        $this->template('titlu', '<?= $titlu ?>');

        $this->assertSame('Pagina', (new View($this->dir, ['titlu' => 'Implicit']))->render('titlu', ['titlu' => 'Pagina']));
    }

    public function test_subdirectory_and_partials(): void
    {
        $this->template('polite/lista', '<ul><?php foreach ($polite as $p): ?><?= $view->render(\'polite/rand\', [\'polita\' => $p]) ?><?php endforeach ?></ul>');
        $this->template('polite/rand', '<li><?= $e($polita) ?></li>');

        $html = (new View($this->dir))->render('polite/lista', ['polite' => ['A1', 'B<2>']]);

        $this->assertSame('<ul><li>A1</li><li>B&lt;2&gt;</li></ul>', $html);
    }

    public function test_template_sees_only_its_variables(): void
    {
        $this->template('izolat', '<?= json_encode(array_keys(get_defined_vars())) ?>|<?= isset($this) ? "this" : "fara this" ?>');

        $html = (new View($this->dir))->render('izolat', ['a' => 1]);

        $this->assertSame('["__file","a","e","view"]|fara this', $html);
    }

    public function test_exception_in_template_discards_partial_output(): void
    {
        $this->template('eroare', 'inceput <?php ob_start(); echo "interior"; throw new LogicException("din template"); ?>');
        $level = ob_get_level();

        try {
            (new View($this->dir))->render('eroare');
            $this->fail('Expected exception.');
        } catch (LogicException $e) {
            $this->assertSame('din template', $e->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }

    public function test_buffers_left_open_by_template_are_included_and_closed(): void
    {
        $this->template('deschis', 'a<?php ob_start(); ?>b');
        $level = ob_get_level();

        $this->assertSame('ab', (new View($this->dir))->render('deschis'));
        $this->assertSame($level, ob_get_level());
    }

    public function test_exists(): void
    {
        $this->template('polite/rand', 'x');
        $view = new View($this->dir);

        $this->assertTrue($view->exists('polite/rand'));
        $this->assertFalse($view->exists('polite/lipsa'));
    }

    // Erori

    public function test_missing_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("View: template directory '{$this->dir}/lipsa' does not exist.");

        new View($this->dir.'/lipsa');
    }

    public function test_missing_template(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("View::render(): template 'polite/lipsa' not found in");

        (new View($this->dir))->render('polite/lipsa');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'urcare' => ['../config'];
        yield 'urcare in interior' => ['polite/../../config'];
        yield 'absolut' => ['/etc/passwd'];
        yield 'cu extensie' => ['salut.php'];
        yield 'backslash' => ['polite\\rand'];
        yield 'gol' => [''];
        yield 'slash final' => ['polite/'];
        yield 'NUL' => ["salut\0"];
    }

    #[DataProvider('invalidNameProvider')]
    public function test_invalid_template_name(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('View::render(): invalid template name');

        (new View($this->dir))->render($name);
    }

    public function test_symlink_outside_directory_is_rejected(): void
    {
        $outside = $this->dir.'-outside.php';
        file_put_contents($outside, 'secret');

        try {
            if (! @symlink($outside, $this->dir.'/link.php')) {
                $this->markTestSkipped('Symlinks are not available (Windows without privileges).');
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("View::render(): template 'link' resolves outside the template directory.");

            (new View($this->dir))->render('link');
        } finally {
            unlink($outside);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidVariableProvider(): iterable
    {
        yield 'rezervat e' => ['e', "the variable name 'e' is reserved"];
        yield 'rezervat view' => ['view', "the variable name 'view' is reserved"];
        yield 'this' => ['this', "the variable name 'this' is reserved"];
        yield 'superglobala' => ['_SESSION', "the variable name '_SESSION' is reserved"];
        yield 'prefix intern' => ['__file', "the variable name '__file' is reserved"];
        yield 'cu cratima' => ['nume-client', "'nume-client' is not a valid variable name"];
        yield 'cu cifra' => ['1a', "'1a' is not a valid variable name"];
    }

    #[DataProvider('invalidVariableProvider')]
    public function test_invalid_variable_names(string $name, string $message): void
    {
        $this->template('salut', 'x');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new View($this->dir))->render('salut', [$name => 1]);
    }

    public function test_invalid_shared_variable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("View::__construct(): the variable name 'e' is reserved.");

        new View($this->dir, ['e' => 1]);
    }

    // escape

    public function test_escape(): void
    {
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&#039;', View::escape('<a href="x">\''));
        $this->assertSame('', View::escape(null));
        $this->assertSame('12', View::escape(12));
        $this->assertSame('1.5', View::escape(1.5));
        $this->assertSame("Ia\u{0219}i", View::escape("Ia\u{0219}i"));
        $this->assertSame('&lt;x&gt;', View::escape(new class implements Stringable
        {
            public function __toString(): string
            {
                return '<x>';
            }
        }));
    }

    public function test_escape_rejects_invalid_utf8(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('View::escape(): text is not valid UTF-8 (length 2 bytes).');

        View::escape("a\xE2");
    }

    private function template(string $name, string $content): void
    {
        file_put_contents($this->dir.'/'.$name.'.php', $content);
    }

    private function deleteDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->deleteDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
