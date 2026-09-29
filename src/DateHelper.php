<?php


namespace ChatStats;


use DateTime;
use Throwable;


class DateHelper
{
    protected static $map = [
        'Понедельник',
        'Вторник',
        'Среда',
        'Четверг',
        'Пятница',
        'Суббота',
        'Воскресение'
    ];

    protected static $months = [
        '01' => 'Январь',
        '02' => 'Февраль',
        '03' => 'Март',
        '04' => 'Апрель',
        '05' => 'Май',
        '06' => 'Июнь',
        '07' => 'Июль',
        '08' => 'Август',
        '09' => 'Сентябрь',
        '10' => 'Октябрь',
        '11' => 'Ноябрь',
        '12' => 'Декабрь',
    ];

    /**
     * Русские названия месяцев в родительном падеже (формат экспорта
     * Telegram: «11 декабря 2022, 22:51:24») → номер месяца.
     */
    protected static $monthsGenitive = [
        'января' => '01',
        'февраля' => '02',
        'марта' => '03',
        'апреля' => '04',
        'мая' => '05',
        'июня' => '06',
        'июля' => '07',
        'августа' => '08',
        'сентября' => '09',
        'октября' => '10',
        'ноября' => '11',
        'декабря' => '12',
    ];

    public static function getDayName(int $weekDayNum): string
    {
        return self::$map[$weekDayNum - 1];
    }

    public static function getMonthName(string $monthNum): string
    {
        return self::$months[$monthNum] ?? $monthNum;
    }

    /**
     * Парсит дату из атрибута title сообщения экспорта в timestamp.
     * Сначала пробует стандартный DateTime (английские экспорты),
     * при неудаче — русский формат «11 декабря 2022, 22:51:24».
     */
    public static function parseExportedDate(?string $date): int
    {
        if ($date === null || $date === '') {
            return 0;
        }

        try {
            return (new DateTime($date))->getTimestamp();
        } catch (Throwable) {
            // ниже — русский формат
        }

        if (preg_match(
            '/^(\d{1,2}) ([а-яё]+) (\d{4}), (\d{1,2}):(\d{2})(?::(\d{2}))?$/ui',
            trim($date),
            $m
        )) {
            $month = self::$monthsGenitive[mb_strtolower($m[2])] ?? null;
            if ($month !== null) {
                return (new DateTime(sprintf(
                    '%s-%s-%s %s:%s:%s',
                    $m[3], $month, str_pad($m[1], 2, '0', STR_PAD_LEFT),
                    str_pad($m[4], 2, '0', STR_PAD_LEFT),
                    str_pad($m[5], 2, '0', STR_PAD_LEFT),
                    $m[6] ?? '00'
                )))->getTimestamp();
            }
        }

        return 0;
    }
}