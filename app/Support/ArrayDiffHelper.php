<?php

namespace App\Support;

class ArrayDiffHelper
{
    /**
     * Shallow-safe deep equality for JSON-like arrays (scalars compared strictly after string trim).
     */
    public static function deepCompare(mixed $old, mixed $new): bool
    {
        if ($old === $new) {
            return true;
        }
        if (! is_array($old) || ! is_array($new)) {
            if ($old instanceof \DateTimeInterface && $new instanceof \DateTimeInterface) {
                return $old->format('Y-m-d') === $new->format('Y-m-d');
            }
            if ($old instanceof \DateTimeInterface) {
                $old = $old->format('Y-m-d');
            }
            if ($new instanceof \DateTimeInterface) {
                $new = $new->format('Y-m-d');
            }

            return trim((string) ($old ?? '')) === trim((string) ($new ?? ''));
        }
        if (array_is_list($old) && array_is_list($new)) {
            if (count($old) !== count($new)) {
                return false;
            }
            foreach ($old as $i => $v) {
                if (! array_key_exists($i, $new) || ! self::deepCompare($v, $new[$i])) {
                    return false;
                }
            }

            return true;
        }
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        foreach ($keys as $k) {
            $a = $old[$k] ?? null;
            $b = $new[$k] ?? null;
            if (! self::deepCompare($a, $b)) {
                return false;
            }
        }

        return true;
    }
}
