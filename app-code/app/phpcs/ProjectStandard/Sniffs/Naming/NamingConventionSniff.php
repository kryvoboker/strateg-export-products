<?php

declare(strict_types=1);

namespace httpdocs\backend\phpcs\ProjectStandard\Sniffs\Naming;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

// PHPCS uses global token constants and its own runtime classes.
// Keep IDE-only compatibility stubs in a separate file, but do not load them here.

final class NamingConventionSniff implements Sniff
{
    private const SNAKE_CASE = '/^[a-z][a-z0-9_]*$/';

    private const CAMEL_CASE = '/^[a-z][A-Za-z0-9]*$/';

    private const PASCAL_CASE = '/^[A-Z][A-Za-z0-9]*$/';

    private const UPPER_SNAKE_CASE = '/^[A-Z][A-Z0-9_]*$/';

    private const ALLOWED_MAGIC_METHODS = [
        '__construct',
        '__destruct',
        '__call',
        '__callStatic',
        '__get',
        '__set',
        '__isset',
        '__unset',
        '__sleep',
        '__wakeup',
        '__serialize',
        '__unserialize',
        '__toString',
        '__invoke',
        '__set_state',
        '__clone',
        '__debugInfo',
    ];

    private const ALLOWED_VARIABLES = [
        'this',
        '_GET',
        '_POST',
        '_SERVER',
        '_COOKIE',
        '_SESSION',
        '_FILES',
        '_ENV',
        'GLOBALS',
        'argv',
        'argc',
        'slug',
        'navigationIcon',
        'navigationGroup',
        'navigationSort',
        'uniqueFor',
    ];

    public function register(): array
    {
        return [
            T_VARIABLE,
            T_FUNCTION,
            T_CLASS,
            T_INTERFACE,
            T_TRAIT,
            T_CONST,
            T_CASE,
            T_ENUM,
        ];
    }

    public function process(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        match ($tokens[$stackPtr]['code']) {
            T_VARIABLE => $this->checkVariable($phpcsFile, $stackPtr),
            T_FUNCTION => $this->checkFunctionOrMethod($phpcsFile, $stackPtr),
            T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM => $this->checkClassLike($phpcsFile, $stackPtr),
            T_CONST => $this->checkConstants($phpcsFile, $stackPtr),
            T_CASE => $this->checkEnumCase($phpcsFile, $stackPtr),
            default => null,
        };
    }

    private function checkVariable(File $file, int $ptr): void
    {
        $tokens = $file->getTokens();
        $name = ltrim($tokens[$ptr]['content'], '$');

        if (in_array($name, self::ALLOWED_VARIABLES, true)) {
            return;
        }

        if (! preg_match(self::SNAKE_CASE, $name)) {
            $file->addError(
                'Variable/property "$%s" must be snake_case.',
                $ptr,
                'VariableNotSnakeCase',
                [$name],
            );
        }
    }

    private function checkFunctionOrMethod(File $file, int $ptr): void
    {
        $tokens = $file->getTokens();

        $conditions = $tokens[$ptr]['conditions'] ?? [];
        $is_method = false;

        foreach ($conditions as $conditionCode) {
            if (in_array($conditionCode, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $is_method = true;

                break;
            }
        }

        if ($is_method === false) {
            return;
        }

        $openParenthesisPtr = $file->findNext(T_OPEN_PARENTHESIS, $ptr + 1);

        if ($openParenthesisPtr === false) {
            return;
        }

        $namePtr = $file->findPrevious(T_STRING, $openParenthesisPtr - 1, $ptr);

        if ($namePtr === false) {
            return;
        }

        $name = $tokens[$namePtr]['content'];

        if (in_array($name, self::ALLOWED_MAGIC_METHODS, true)) {
            return;
        }

        if (! preg_match(self::CAMEL_CASE, $name)) {
            $file->addError(
                'Function/method "%s" must be camelCase.',
                $namePtr,
                'FunctionOrMethodNotCamelCase',
                [$name],
            );
        }
    }

    private function checkClassLike(File $file, int $ptr): void
    {
        $tokens = $file->getTokens();

        if ($tokens[$ptr]['code'] === T_CLASS && $this->isAnonymousClass($file, $ptr)) {
            return;
        }

        $scopeOpenerPtr = $tokens[$ptr]['scope_opener'] ?? null;

        if ($scopeOpenerPtr === null) {
            return;
        }

        $namePtr = $file->findNext(T_STRING, $ptr + 1, $scopeOpenerPtr);

        if ($namePtr === false) {
            return;
        }

        $name = $tokens[$namePtr]['content'];

        if (! preg_match(self::PASCAL_CASE, $name)) {
            $file->addError(
                'Class/interface/trait/enum "%s" must be PascalCase.',
                $namePtr,
                'ClassLikeNotPascalCase',
                [$name],
            );
        }
    }

    private function checkConstants(File $file, int $ptr): void
    {
        $tokens = $file->getTokens();

        $endPtr = $file->findNext(T_SEMICOLON, $ptr + 1);

        if ($endPtr === false) {
            return;
        }

        for ($i = $ptr + 1; $i < $endPtr; $i++) {
            if ($tokens[$i]['code'] !== T_STRING) {
                continue;
            }

            $previousNonEmpty = $file->findPrevious(
                [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                $i - 1,
                $ptr,
                true,
            );

            if ($previousNonEmpty === false) {
                continue;
            }

            if (! in_array($tokens[$previousNonEmpty]['code'], [T_CONST, T_COMMA], true)) {
                continue;
            }

            $nextNonEmpty = $file->findNext(
                [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
                $i + 1,
                $endPtr,
                true,
            );

            if ($nextNonEmpty === false) {
                continue;
            }

            if ($tokens[$nextNonEmpty]['code'] !== T_EQUAL) {
                continue;
            }

            $name = $tokens[$i]['content'];

            if (! preg_match(self::UPPER_SNAKE_CASE, $name)) {
                $file->addError(
                    'Constant "%s" must be UPPER_SNAKE_CASE.',
                    $i,
                    'ConstantNotUpperSnakeCase',
                    [$name],
                );
            }
        }
    }

    private function checkEnumCase(File $file, int $ptr): void
    {
        $tokens = $file->getTokens();

        if (! $this->isInsideEnum($tokens[$ptr])) {
            return;
        }

        $nextStringPtr = $file->findNext(T_STRING, $ptr + 1);

        if ($nextStringPtr === false) {
            return;
        }

        $name = $tokens[$nextStringPtr]['content'];

        if (! preg_match(self::UPPER_SNAKE_CASE, $name)) {
            $file->addError(
                'Enum case "%s" must be UPPER_SNAKE_CASE.',
                $nextStringPtr,
                'EnumCaseNotUpperSnakeCase',
                [$name],
            );
        }
    }

    private function isAnonymousClass(File $file, int $ptr): bool
    {
        $tokens = $file->getTokens();

        $previousNonEmpty = $file->findPrevious(
            [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT_WHITESPACE],
            $ptr - 1,
            null,
            true,
        );

        if ($previousNonEmpty === false) {
            return false;
        }

        return $tokens[$previousNonEmpty]['code'] === T_NEW;
    }

    private function isInsideEnum(array $token): bool
    {
        if (! isset($token['conditions'])) {
            return false;
        }

        return in_array(T_ENUM, $token['conditions'], true);
    }
}
