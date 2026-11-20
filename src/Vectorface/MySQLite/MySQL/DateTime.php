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
     * @return int|null The date converted to a number of days since year 0, or NULL if the date is NULL or invalid.
     */
    public static function mysql_to_days($date)
    {
        $parts = static::parseDateTime($date);

        /* MySQL accepts year 0 here, and doesn't treat it as a leap year, just like year 1. */
        if ($parts === null || !checkdate($parts['month'], $parts['day'], max($parts['year'], 1))) {
            return null;
        }

        /* MySQL's own day number calculation (calc_daynr), so results match MySQL's exactly. */
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
     * @return int|null The number of seconds since the unix epoch, 0 if the date is out of range (including zero
     *     dates), or NULL if the date is NULL or invalid.
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

        /* Like MySQL, dates outside the TIMESTAMP range ('1970-01-01 00:00:01' to '3001-01-19 03:14:07' UTC) give 0. */
        return $timestamp < 1 || $timestamp > 32536782847 ? 0 : $timestamp;
    }

    /**
     * YEAR - Return the year of a date
     *
     * Like MySQL, incomplete dates such as '2010-00-00' are accepted.
     *
     * @param string $date A date or datetime, e.g. '2010-11-30' or '2010-11-30 12:34:56'.
     * @return int|null The year, or NULL if the date is NULL or invalid.
     */
    public static function mysql_year($date)
    {
        $parts = static::parseIncompleteDate($date);
        return $parts === null ? null : $parts['year'];
    }

    /**
     * MONTH - Return the month of a date
     *
     * Like MySQL, incomplete dates such as '2010-00-00' are accepted.
     *
     * @param string $date A date or datetime, e.g. '2010-11-30' or '2010-11-30 12:34:56'.
     * @return int|null The month, from 1 to 12, or 0 for dates with a zero month like '2008-00-00'. NULL if the
     *     date is NULL or invalid.
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
     * Like MySQL, the result is clamped to the TIME range of -838:59:59 to 838:59:59. Fractional seconds are shown
     * (to 6 digits) if either argument has them.
     *
     * @param string $time1 A time (e.g. '-1 12:00:00.5') or datetime (e.g. '2010-11-30 12:00:00').
     * @param string $time2 A value of the same kind as $time1.
     * @return string|null $time1 - $time2 as 'HH:MM:SS[.ffffff]', or NULL if either argument is NULL or invalid, or
     *     if one is a time and the other is a datetime.
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
     * Parse a MySQL date or datetime string, like 'YYYY-MM-DD[ HH:MM:SS[.ffffff]]' or 'YYYYMMDD[HHMMSS[.ffffff]]'.
     *
     * Any punctuation may separate the date parts, and 'T' may separate the date and time, as MySQL allows.
     * Date parts are not validated here, because some functions accept incomplete dates like '2010-00-00'.
     *
     * @param mixed $value The value to parse.
     * @return array|null The year, month, day, micros (since midnight), and fraction (whether fractional seconds
     *     were given), or null if the value is not a date or datetime.
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
     * Parse a MySQL time string, like '[-][D ]HH:MM[:SS[.ffffff]]'. Hours may exceed 23.
     *
     * @param mixed $value The value to parse.
     * @return array|null The signed time in micros, and fraction (whether fractional seconds were given), or null
     *     if the value is not a time.
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
     * Convert the digits after a decimal point to microseconds, ignoring digits beyond the sixth.
     *
     * @param string $digits The fractional digits.
     * @return int The number of microseconds.
     */
    private static function parseFraction($digits)
    {
        return (int)str_pad(substr($digits, 0, 6), 6, '0');
    }

    /**
     * Parse a date that may have zero parts, like '2010-00-00' or '0000-00-00', which some MySQL functions accept.
     *
     * Year 0 is allowed, and isn't a leap year. Parts that are given must still be valid, so '2010-02-30' and
     * '2010-13-00' are rejected.
     *
     * @param mixed $value The value to parse.
     * @return array|null Parts as from parseDateTime, or null if the value is not a valid date.
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
     * Check that parsed date parts form a real calendar date, with no zero parts.
     *
     * @param array|null $parts Parts from parseDateTime.
     * @return bool True if the date is complete and valid.
     */
    private static function isCompleteDate($parts)
    {
        return $parts !== null && $parts['year'] > 0 && checkdate($parts['month'], $parts['day'], $parts['year']);
    }

    /**
     * Get the UTC timestamp of midnight on a date, so date arithmetic is unaffected by the local time zone.
     *
     * @param array $parts Parts from parseDateTime.
     * @return int Seconds since the Unix epoch.
     */
    private static function dateToTimestamp($parts)
    {
        $date = sprintf('%04d-%02d-%02d', $parts['year'], $parts['month'], $parts['day']);
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
    }
}
