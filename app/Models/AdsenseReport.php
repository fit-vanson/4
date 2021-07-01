<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdsenseReport extends Model
{
    //
	protected $table='adsense_report';
	protected $primaryKey='id';

	protected $fillable =  ['pub_id', 'date', 'pageview', 'impression','cpc','ctr','total'];
	public $timestamps = true;
}
