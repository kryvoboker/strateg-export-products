<?php

declare(strict_types=1);

// IDE-only compatibility stubs for static analysis and code completion.
// This file must not be required from the sniff itself, otherwise it can shadow the real PHPCS classes at runtime.

namespace PHP_CodeSniffer\Sniffs {
    use httpdocs\backend\phpcs\ProjectStandard\Sniffs\Naming\File;

    if (interface_exists(Sniff::class, false) === false) {
        interface Sniff
        {
            public function register();

            public function process(File $phpcsFile, int $stackPtr);
        }
    }
}

namespace httpdocs\backend\phpcs\ProjectStandard\Sniffs\Naming {
    if (class_exists(File::class, false) === false) {
        class File
        {
            public function getTokens(): array
            {
                return [];
            }

            public function findNext($types, $start, $end = null, $exclude = false)
            {
                return false;
            }

            public function findPrevious($types, $start, $end = null, $exclude = false)
            {
                return false;
            }

            public function addError(string $error, int $stackPtr, string $code = '', array $data = []): void
            {
            }
        }
    }
}

namespace {
    if (defined('T_OPEN_PARENTHESIS') === false) {
        define('T_OPEN_PARENTHESIS', 'PHPCS_T_OPEN_PARENTHESIS');
    }

    if (defined('T_CLOSE_PARENTHESIS') === false) {
        define('T_CLOSE_PARENTHESIS', 'PHPCS_T_CLOSE_PARENTHESIS');
    }

    if (defined('T_SEMICOLON') === false) {
        define('T_SEMICOLON', 'PHPCS_T_SEMICOLON');
    }

    if (defined('T_COMMA') === false) {
        define('T_COMMA', 'PHPCS_T_COMMA');
    }

    if (defined('T_DOC_COMMENT_WHITESPACE') === false) {
        define('T_DOC_COMMENT_WHITESPACE', 'PHPCS_T_DOC_COMMENT_WHITESPACE');
    }
}
