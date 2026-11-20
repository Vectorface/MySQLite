<?php

namespace Vectorface\MySQLite\MySQL;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Provides Date and Time MySQL compatibility functions for SQLite.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/date-and-time-functions.html
 */
trait DateTime
{
    /**
     * NOW - Return the current date and time
     *
     * @return string The current timestamp, in MySQL's date format.
     */
    public static function mysql_now()
    {
        return date("Y-m-d H:i:s");
    }

    /**
     * CURDATE - Return the current date
     *
     * @return string The current date, in MySQL's date format.
     */
    public static function mysql_curdate(): string
    {
        return date("Y-m-d");
    }

    /**
     * TO_DAYS - Return the date argument converted to days
     *
     * @param string $date A date to be converted to days.
     * @return int|null Days since year 0, or NULL if the date is NULL or invalid.
     */
    public static function mysql_to_days($date)
    {
        $parts = static::parseDateTime($date);

        /* Year 0 is valid, and not a leap year */
        if ($parts === null || !checkdate($parts['month'], $parts['day'], max($parts['year'], 1))) {
            return null;
        }

        /* MySQL's calc_daynr() */
        [$year, $month] = [$parts['year'], $parts['month']];
        $days = 365 * $year + 31 * ($month - 1) + $parts['day'];
        if ($month <= 2) {
            $year--;
        } else {
            $days -= intdiv($month * 4 + 23, 10);
        }
        return $days + intdiv($year, 4) - intdiv((intdiv($year, 100) + 1) * 3, 4);
    }

    /**
     * UNIX_TIMESTAMP - Return a UNIX timestamp
     *
     * @param string $date The date to be converted to a unix timestamp. Defaults to the current date/time.
     * @return int|null Seconds since the epoch, 0 if out of range, or NULL if the date is NULL or invalid.
     */
    public static function mysql_unix_timestamp($date = null)
    {
        if (func_num_args() === 0) {
            return time();
        } elseif ($date === null) {
            return null;
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }

        /* MySQL's TIMESTAMP range */
        return $timestamp < 1 || $timestamp > 32536782847 ? 0 : $timestamp;
    }

    /**
     * YEAR - Return the year of a date, allowing zero parts like '2010-00-00'
     *
     * @param string $date A date or datetime.
     * @return int|null The year, or NULL if the date is NULL or invalid.
     */
    public static function mysql_year($date)
    {
        $parts = static::parseIncompleteDate($date);
        return $parts === null ? null : $parts['year'];
    }

    /**
     * MONTH - Return the month of a date, allowing zero parts like '2010-00-00'
     *
     * @param string $date A date or datetime.
     * @return int|null The month (0 to 12), or NULL if the date is NULL or invalid.
     */
    public static function mysql_month($date)
    {
        $parts = static::parseIncompleteDate($date);
        return $parts === null ? null : $parts['month'];
    }

    /**
     * DATEDIFF - Return the number of days from one date to another, ignoring any time parts
     *
     * @param string $date1 A date or datetime.
     * @param string $date2 A date or datetime.
     * @return int|null $date1 - $date2 in days, or NULL if either date is NULL or invalid.
     */
    public static function mysql_datediff($date1, $date2)
    {
        $parts1 = static::parseDateTime($date1);
        $parts2 = static::parseDateTime($date2);
        if (!static::isCompleteDate($parts1) || !static::isCompleteDate($parts2)) {
            return null;
        }
        return intdiv(static::dateToTimestamp($parts1) - static::dateToTimestamp($parts2), 86400);
    }

    /**
     * TIMEDIFF - Return the difference between two times or two datetimes, as a time
     *
     * Clamped to MySQL's TIME range, ±838:59:59. Shows microseconds if either argument has a fraction.
     *
     * @param string $time1 A time (e.g. '-1 12:00:00.5') or datetime.
     * @param string $time2 A value of the same kind as $time1.
     * @return string|null $time1 - $time2, or NULL if either is NULL or invalid, or they're of different kinds.
     */
    public static function mysql_timediff($time1, $time2)
    {
        if (($parts1 = static::parseDateTime($time1)) !== null && ($parts2 = static::parseDateTime($time2)) !== null) {
            if (!static::isCompleteDate($parts1) || !static::isCompleteDate($parts2)) {
                return null;
            }
            $micros1 = static::dateToTimestamp($parts1) * 1000000 + $parts1['micros'];
            $micros2 = static::dateToTimestamp($parts2) * 1000000 + $parts2['micros'];
        } elseif (($parts1 = static::parseTime($time1)) !== null && ($parts2 = static::parseTime($time2)) !== null) {
            $micros1 = $parts1['micros'];
            $micros2 = $parts2['micros'];
        } else {
            return null;
        }

        $diff = $micros1 - $micros2;
        $max = (838 * 3600 + 59 * 60 + 59) * 1000000;
        $abs = min(abs($diff), $max);
        $seconds = intdiv($abs, 1000000);

        return sprintf(
            '%s%02d:%02d:%02d',
            $diff < 0 ? '-' : '',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        ) . ($parts1['fraction'] || $parts2['fraction'] ? sprintf('.%06d', $abs % 1000000) : '');
    }

    /**
     * Parse 'YYYY-MM-DD[ HH:MM:SS[.ffffff]]' (any punctuation; 'T' allowed) or 'YYYYMMDD[HHMMSS[.ffffff]]'.
     *
     * Date parts aren't validated, since some functions allow zero parts.
     *
     * @return array|null [year, month, day, micros (since midnight), fraction (bool)], or null if not a date.
     */
    private static function parseDateTime($value)
    {
        $pattern = '/^\s*(\d{4})[[:punct:]](\d{1,2})[[:punct:]](\d{1,2})'
            . '(?:[ T](\d{1,2})[[:punct:]](\d{1,2})(?:[[:punct:]](\d{1,2})(?:\.(\d+))?)?)?\s*$/';
        $compact = '/^\s*(\d{4})(\d{2})(\d{2})(?:(\d{2})(\d{2})(\d{2})(?:\.(\d+))?)?\s*$/'; /* Same groups */
        $value = $value === null ? '' : (string)$value;
        if (!preg_match($pattern, $value, $m) && !preg_match($compact, $value, $m)) {
            return null;
        }

        [$hour, $minute, $second] = [(int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0)];
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return [
            'year' => (int)$m[1],
            'month' => (int)$m[2],
            'day' => (int)$m[3],
            'micros' => (($hour * 60 + $minute) * 60 + $second) * 1000000 + static::parseFraction($m[7] ?? ''),
            'fraction' => isset($m[7]),
        ];
    }

    /**
     * Parse '[-][D ]HH:MM[:SS[.ffffff]]'. Hours may exceed 23.
     *
     * @return array|null [micros (signed), fraction (bool)], or null if not a time.
     */
    private static function parseTime($value)
    {
        $pattern = '/^\s*(-)?(?:(\d+) +)?(\d+):(\d{1,2})(?::(\d{1,2})(?:\.(\d+))?)?\s*$/';
        if ($value === null || !preg_match($pattern, (string)$value, $m)) {
            return null;
        }

        [$hours, $minute, $second] = [(int)$m[2] * 24 + (int)$m[3], (int)$m[4], (int)($m[5] ?? 0)];
        if ($minute > 59 || $second > 59) {
            return null;
        }

        $micros = (($hours * 60 + $minute) * 60 + $second) * 1000000 + static::parseFraction($m[6] ?? '');
        return [
            'micros' => $m[1] === '-' ? -$micros : $micros,
            'fraction' => isset($m[6]),
        ];
    }

    /**
     * Convert fractional-second digits to microseconds.
     */
    private static function parseFraction($digits)
    {
        return (int)str_pad(substr($digits, 0, 6), 6, '0');
    }

    /**
     * Parse a date allowing zero parts, like '2010-00-00'. Other parts must be valid; year 0 isn't a leap year.
     */
    private static function parseIncompleteDate($value)
    {
        $parts = static::parseDateTime($value);
        if ($parts === null || $parts['month'] > 12 || $parts['day'] > 31) {
            return null;
        }
        if ($parts['month'] && $parts['day'] && !checkdate($parts['month'], $parts['day'], max($parts['year'], 1))) {
            return null;
        }
        return $parts;
    }

    /**
     * Whether parsed parts form a valid date with no zero parts.
     */
    private static function isCompleteDate($parts)
    {
        return $parts !== null && $parts['year'] > 0 && checkdate($parts['month'], $parts['day'], $parts['year']);
    }

    /**
     * UTC timestamp of midnight on a date, so the local time zone doesn't matter.
     */
    private static function dateToTimestamp($parts)
    {
        $date = sprintf('%04d-%02d-%02d', $parts['year'], $parts['month'], $parts['day']);
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
    }
}
