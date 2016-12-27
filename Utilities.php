<?php
/*---------------------------------------------------------------------------------------------
 *  Copyright (c) Microsoft Corporation. All rights reserved.
 *  Licensed under the MIT License. See License.txt in the project root for license information.
 *--------------------------------------------------------------------------------------------*/

namespace PhpParser;

use PhpParser\Node\Node;

class Utilities {
    public static function getDiagnostics($node) {
        $tokenKindToText = array_flip(array_merge(OPERATORS_AND_PUNCTUATORS, KEYWORDS, RESERVED_WORDS));

        if ($node instanceof SkippedToken) {
            // TODO - consider also attaching parse context information to skipped tokens
            // this would allow us to provide more helpful error messages that inform users what to do
            // about the problem rather than simply pointing out the mistake.
            return yield new Diagnostic(
                DiagnosticKind::Error,
                "Unexpected '" .
                (isset($tokenKindToText[$node->kind])
                    ? $tokenKindToText[$node->kind]
                    : Token::getTokenKindNameFromValue($node->kind)) .
                "'",
                $node->start,
                $node->getEnd() - $node->start
            );
        } elseif ($node instanceof MissingToken) {
            return yield new Diagnostic(
                DiagnosticKind::Error,
                "'" .
                (isset($tokenKindToText[$node->kind])
                    ? $tokenKindToText[$node->kind]
                    : Token::getTokenKindNameFromValue($node->kind)) .
                "' expected.",
                $node->start,
                $node->getEnd() - $node->start
            );
        }

        if ($node === null || $node instanceof Token) {
            return;
        }

        if ($node instanceof Node) {
            switch ($node->kind) {
                case NodeKind::MethodNode:
                    foreach ($node->modifiers as $modifier) {
                        if ($modifier->kind === TokenKind::VarKeyword) {
                            yield new Diagnostic(
                                DiagnosticKind::Error,
                                "Unexpected modifier '" . $tokenKindToText[$modifier->kind] . "'",
                                $modifier->start,
                                $modifier->getEnd() - $modifier->start
                            );
                        }
                    }
                    break;
            }
        }

        foreach ($node->getChildNodesAndTokens() as $child) {
            yield from Utilities::getDiagnostics($child);
        }
    }
}