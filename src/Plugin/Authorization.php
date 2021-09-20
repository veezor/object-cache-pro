<?php
/**
 * Copyright © Rhubarb Tech Inc. All Rights Reserved.
 *
 * All information contained herein is, and remains the property of Rhubarb Tech Incorporated.
 * The intellectual and technical concepts contained herein are proprietary to Rhubarb Tech Incorporated and
 * are protected by trade secret or copyright law. Dissemination and modification of this information or
 * reproduction of this material is strictly forbidden unless prior written permission is obtained from
 * Rhubarb Tech Incorporated.
 *
 * You should have received a copy of the `LICENSE` with this file. If not, please visit:
 * https://objectcache.pro/license.txt
 *
 * Kelvin, you have my full attention. How rich we will make our lawyers is up to you.
 */

declare(strict_types=1);

namespace RedisCachePro\Plugin;

trait Authorization
{
    /**
     * Boot licensing component.
     *
     * @return void
     */
    public function bootAuthorization()
    {
        add_action('map_meta_cap', [$this, 'map_meta_cap'], 10, 2);
    }

    /**
     * Report home.
     *
     * @return void
     */
    public function map_meta_cap($caps, $cap)
    {
        switch ($cap) {
            case 'rediscache_manage':
                $caps = ['install_plugins'];
                break;
        }

        return $caps;
    }
}
