<?php

namespace ChatStats;

/**
 * Нормализация имён отправителей.
 */
final class UserHelper
{
    /**
     * Срезает суффикс " via @channel" и применяет карту «ник → настоящее имя».
     */
    public static function norm(string $username, array $namesMap): string
    {
        $viaPos = mb_strrpos($username, ' via @');
        if ($viaPos !== false) {
            $username = mb_substr($username, 0, $viaPos);
        }

        foreach ($namesMap as $nick => $realName) {
            $username = str_replace($nick, $realName, $username);
        }

        return trim($username);
    }
}