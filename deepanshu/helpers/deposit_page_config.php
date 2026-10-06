<?php
if (!function_exists('deposit_page_first_gears')) {
    function deposit_page_first_gears()
    {
        return [
            ['min' => 200, 'max' => 499, 'promo' => 0],
            ['min' => 500, 'max' => 999, 'promo' => 0],
            ['min' => 1000, 'max' => 999999, 'promo' => 0],
        ];
    }
}
