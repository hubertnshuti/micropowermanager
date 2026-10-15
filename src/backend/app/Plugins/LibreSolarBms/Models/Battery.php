<?php

namespace App\Plugins\LibreSolarBms\Models;

use App\Models\Base\BaseModel;

/**
 * @property int               $id
 * @property string            $node_id
 * @property string            $label
 * @property string            $source
 * @property string            $adapter_id
 * @property string|null       $active_session_id
 * @property list<string>|null $retired_sessions
 */
class Battery extends BaseModel {
    protected $table = 'libre_solar_bms_batteries';
    protected $casts = ['retired_sessions' => 'array'];
}
