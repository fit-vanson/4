<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdsenseAccount extends Model
{
    //
	protected $table='adsense_accounts';
	protected $primaryKey='id';

	protected $fillable =  ['adsense_pub_id', 'access_token', 'adsense_name', 'note','g_client_id','g_secret','error'];
	public $timestamps = true;

}
