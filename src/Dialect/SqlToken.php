<?php

namespace Tknoweb\AiSqlAssistantBundle\Dialect;

/**
 * One token of a query of the model, as SqlDialect::tokenize() reads it, comments and spaces left out.
 */
final class SqlToken
{
    // An unquoted keyword or name
    public const WORD = 'word';
    // A name or a string between double quotes, backticks or brackets, which the dialect may read as either
    public const QUOTED = 'quoted';
    // A string between single quotes
    public const STRING = 'string';
    public const NUMBER = 'number';
    // Any other character: an operator, a parenthesis, a comma...
    public const SYMBOL = 'symbol';

    // $text is the token as written, its quotes left out for a quoted name or a string
    public function __construct(
        public readonly string $type,
        public readonly string $text,
    ) {
    }

    public function isWord(string ...$words): bool
    {
        return self::WORD === $this->type && in_array(strtolower($this->text), $words, true);
    }

    public function isSymbol(string $symbol): bool
    {
        return self::SYMBOL === $this->type && $symbol === $this->text;
    }

    /**
     * Whether the token can name a table, a column or a function: an unquoted word, or a quoted name.
     */
    public function isName(): bool
    {
        return self::WORD === $this->type || self::QUOTED === $this->type;
    }

    /**
     * The name, case left out, so that the checks compare names whatever the case folding of the dialect.
     */
    public function getName(): string
    {
        return mb_strtolower($this->text);
    }
}
