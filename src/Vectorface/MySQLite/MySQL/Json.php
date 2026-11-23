<?php

namespace Vectorface\MySQLite\MySQL;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Provides JSON MySQL compatibility functions for SQLite.
 *
 * These follow MariaDB where it differs from MySQL: JSON_EXISTS is MariaDB-only, and JSON_CONTAINS returns 0 for a
 * missing path. Paths support $, .key, ."quoted key" and [n]; wildcards, ** and [last] throw. JSON_VALUE returns
 * text like MariaDB, but numbers in canonical form where MariaDB keeps their original text (1.5, not 1.50).
 *
 * https://mariadb.com/kb/en/json-functions/
 */
trait Json
{
    /**
     * JSON_VALUE - Extract a scalar
     *
     * @param string|null $doc A JSON document.
     * @param string|null $path The path to the value.
     * @return string|null The scalar as text (booleans as '1' or '0'), or NULL for null, arrays, objects, missing
     * paths and invalid JSON.
     */
    public static function mysql_json_value($doc, $path)
    {
        [$found, $value] = static::jsonFind($doc, $path);
        if (!$found || $value === null || is_array($value) || $value instanceof stdClass) {
            return null;
        }
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => json_encode($value),
            is_string($value) => json_decode("\"{$value}\""),
            default => (string)$value,
        };
    }

    /**
     * JSON_CONTAINS - Whether a document contains a candidate at a path
     *
     * Arrays contain their elements and their subsets; objects contain subsets of their keys. Numbers compare by
     * value; other scalars compare by type and value, so 5 and "5" differ. Strings compare as written, escapes
     * included, so "a\/b" and "a/b" differ; keys compare unescaped.
     *
     * @param string|null $doc A JSON document.
     * @param string|null $candidate A JSON document to look for.
     * @param string $path The path to search within.
     * @return int|null 1 or 0, 0 for a missing path, or NULL for NULL or invalid JSON.
     */
    public static function mysql_json_contains($doc, $candidate, $path = '$')
    {
        [$valid, $needle] = static::jsonDecode($candidate);
        if (!$valid) {
            return null;
        }
        [$found, $value, $validDoc] = static::jsonFind($doc, $path);
        if (!$validDoc) {
            return null;
        }
        return (int)($found && static::jsonContains($value, $needle));
    }

    /**
     * JSON_EXISTS - Whether a path exists in a document
     *
     * @param string|null $doc A JSON document.
     * @param string|null $path The path.
     * @return int|null 1 or 0, or NULL for NULL or invalid JSON.
     */
    public static function mysql_json_exists($doc, $path)
    {
        [$found, , $validDoc] = static::jsonFind($doc, $path);
        return $validDoc ? (int)$found : null;
    }

    /**
     * JSON_LENGTH - Count an array's elements or an object's keys
     *
     * @param string|null $doc A JSON document.
     * @param string $path The path to measure.
     * @return int|null The count, 1 for a scalar, or NULL for a missing path, NULL or invalid JSON.
     */
    public static function mysql_json_length($doc, $path = '$')
    {
        [$found, $value] = static::jsonFind($doc, $path);
        if (!$found) {
            return null;
        }
        return match (true) {
            is_array($value) => count($value),
            $value instanceof stdClass => count(get_object_vars($value)),
            default => 1,
        };
    }

    /**
     * Find the value at a path.
     *
     * @return array [found, value, valid]: whether it was found, the value, and whether the document was valid JSON.
     */
    private static function jsonFind($doc, $path): array
    {
        [$valid, $value] = static::jsonDecode($doc);
        if (!$valid || $path === null) {
            return [false, null, false];
        }

        foreach (static::jsonPath((string)$path) as $step) {
            if (is_int($step) && is_array($value) && array_key_exists($step, $value)) {
                $value = $value[$step];
            } elseif (is_string($step) && $value instanceof stdClass && property_exists($value, $step)) {
                $value = $value->$step;
            } else {
                return [false, null, true];
            }
        }
        return [true, $value, true];
    }

    /**
     * Decode JSON, keeping objects as stdClass so {} and [] stay distinct, and string values as written (escaped).
     *
     * @return array [valid, value]: whether it was valid JSON, and the value.
     */
    private static function jsonDecode($json): array
    {
        if ($json === null) {
            return [false, null];
        }
        $json = (string)$json;
        try {
            json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [false, null];
        }
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]|[^\s{}\[\],:"]+/', $json, $m);
        $i = 0;
        return [true, static::jsonParse($m[0], $i)];
    }

    /**
     * Build a value from the tokens of valid JSON, keeping string values as their escaped text.
     *
     * @param string[] $tokens Strings, punctuation, and bare literals (numbers, true, false, null).
     * @param int $i The index of the value's first token, advanced past its last.
     */
    private static function jsonParse(array $tokens, int &$i)
    {
        $token = $tokens[$i++];
        if ($token[0] === '"') {
            return substr($token, 1, -1);
        }
        if ($token !== '{' && $token !== '[') {
            return json_decode($token);
        }

        $close = $token === '{' ? '}' : ']';
        $result = $token === '{' ? new stdClass() : [];
        while ($tokens[$i] !== $close) {
            if ($result instanceof stdClass) {
                $key = json_decode($tokens[$i]);
                $i += 2; /* The key and its colon */
                $result->$key = static::jsonParse($tokens, $i);
            } else {
                $result[] = static::jsonParse($tokens, $i);
            }
            if ($tokens[$i] === ',') {
                $i++;
            }
        }
        $i++;
        return $result;
    }

    /**
     * Parse a path into keys (strings) and indexes (ints).
     *
     * @return array<string|int>
     */
    private static function jsonPath(string $path): array
    {
        $pattern = '/\G(?:\.([A-Za-z0-9_$]+)|\."([^"]*)"|\[(\d+)\])/';
        if (!str_starts_with($path, '$')) {
            throw new InvalidArgumentException("Unsupported JSON path: $path");
        }

        $steps = [];
        $offset = 1;
        while ($offset < strlen($path)) {
            if (!preg_match($pattern, $path, $m, PREG_UNMATCHED_AS_NULL, $offset)) {
                throw new InvalidArgumentException("Unsupported JSON path: $path");
            }
            $steps[] = isset($m[3]) ? (int)$m[3] : $m[2] ?? $m[1];
            $offset += strlen($m[0]);
        }
        return $steps;
    }

    /**
     * Whether a target contains a candidate, by MariaDB's JSON_CONTAINS rules.
     */
    private static function jsonContains($target, $candidate): bool
    {
        if (is_array($target)) {
            return static::jsonArrayContains($target, $candidate);
        }
        if ($target instanceof stdClass) {
            return $candidate instanceof stdClass && static::jsonObjectContains($target, $candidate);
        }
        return static::jsonScalarEquals($target, $candidate);
    }

    /**
     * An array contains every element of an array candidate, or an element containing any other candidate.
     */
    private static function jsonArrayContains(array $target, $candidate): bool
    {
        if (is_array($candidate)) {
            foreach ($candidate as $item) {
                if (!static::jsonArrayContains($target, $item)) {
                    return false;
                }
            }
            return true;
        }
        foreach ($target as $item) {
            if (static::jsonContains($item, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * An object contains each of the candidate's keys, with a value containing the candidate's.
     */
    private static function jsonObjectContains(stdClass $target, stdClass $candidate): bool
    {
        foreach (get_object_vars($candidate) as $key => $value) {
            if (!property_exists($target, $key) || !static::jsonContains($target->$key, $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Numbers are equal by value; other scalars by type and value.
     */
    private static function jsonScalarEquals($target, $candidate): bool
    {
        $isNumber = fn($v) => is_int($v) || is_float($v);
        return $isNumber($target) && $isNumber($candidate) ? $target == $candidate : $target === $candidate;
    }
}
