<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use League\Csv\Reader;

use DB;
use Zttp\Zttp;

class ImportOrders extends Command {
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'rm:importOrders';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Import Orders into Shopify';

	private $shopify;

	/**
	 * Create a new command instance.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();
		$this->shopify = new \RocketCode\Shopify\Client;
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle() {

		$csv = Reader::createFromPath( storage_path( 'Orders-2017.csv' ), 'r' );
		$csv->setHeaderOffset( 0 );

		$header = $csv->getHeader(); //returns the CSV header record
		$records = $csv->getRecords(); //returns all the CSV records as an Iterator object

		$allOrders = [];
		collect( $records )->each( function ( $item ) use ( &$allOrders ) {
			$allOrders[ $item[ "Magento ID" ] ][] = $item;
		} );

		collect( $allOrders )->each( function ( $item, $key ) {

			$srcOrder = $item[ 0 ];
			$srcOrder[ "Order Date" ] = Carbon::createFromFormat(
				"n/d/y G:i", $srcOrder[ "Order Date" ]
			)->toIso8601String();

			//echo 'Magento_ID::' . $srcOrder[ "Magento ID" ];

//			$result = $this->shopify::Order()->find( [
//				'args' => [
//					'query' => '"' . 'Magento_ID::' . $srcOrder[ "Magento ID" ] . '"'
//					// Prod:  magento_order_number::
//				]
//			] );
			for ( $tmppage = 1613; $tmppage <= 3000; $tmppage++ ) {
				$args = [
					'filters' => [
						'updated_at_min' => '2017-01-01T00:00:00-00:00',
						'updated_at_max' => '2017-10-11T23:59:59-00:00',
						'limit' => 250,
						'page' => $tmppage
					]
				];
				$result = $this->shopify::Order()->all( $args );

	//			dd( $result );
	//			dd( json_decode( $result, true ) );
				$result = json_decode($result);
				$test = 1;

				foreach($result as $ordObj) {
	//				if ($test >= 1) { continue; }
					//$flight = new Flight;
					echo "Inserting record #" . $test . " for page #" . $tmppage . "\n";
					$tmpvar = json_encode($ordObj);
					$ordercall = DB::table('shopify_json_orders')->insert(
						['text'=>$tmpvar]
					);
					$test++;
				}
			}
			dd('end');

			$shopOrder = [
				"email" => $srcOrder[ "Email" ],
				"closed_at" => $srcOrder[ "Order Date" ],
				"created_at" => $srcOrder[ "Order Date" ],
				"gateway" => "manual",
				"total_price" => $srcOrder[ "SubTotal" ],
				"total_tax" => $srcOrder[ "Tax" ],
				"taxes_included" => false,
				"currency" => "USD",
				"financial_status" => "paid",
				"confirmed" => true,
				"name" => $srcOrder[ "Magento ID" ],

				"processed_at" => $srcOrder[ "Order Date" ],
				"phone" => $srcOrder[ "Billing Phone" ],
				"order_number" => $srcOrder[ "Magento ID" ],
				"discount_codes" => [],

				"payment_gateway_names" => [
					"manual"
				],
				"processing_method" => "manual",
				"fulfillment_status" => "fulfilled",
				"tags" => "magento_order_number::{$srcOrder[ "Magento ID" ]},Oneill::fulfilled,Oneill::Pulled,Riskified::approved,TestImport",
			];

			if ( $srcOrder[ "Transaction Gateway" ] ) {
				if ( $srcOrder[ "Transaction Gateway" ] == "gene_braintree_creditcard" )
					$srcOrder[ "Transaction Gateway" ] = "Braintree";
				$shopOrder[ "tags" ] .= ",Payment_Method::" . $srcOrder[ "Transaction Gateway" ];
				//dd( $shopOrder );
			}

			$shopOrder[ "customer" ] = [
				"email" => $srcOrder[ "Email" ],
				"first_name" => $srcOrder[ "Billing First" ],
				"last_name" => $srcOrder[ "Billing Last" ],
				//"phone" => $srcOrder[ "Billing Phone" ],
				"default_address" => [
					"first_name" => $srcOrder[ "Billing First" ],
					"last_name" => $srcOrder[ "Billing Last" ],
					"address1" => $srcOrder[ "Billing Address" ],
					"city" => $srcOrder[ "Billing City" ],
					"province" => $srcOrder[ "Billing Province Code" ],
					"zip" => $srcOrder[ "Billing Zip" ],
					"phone" => $srcOrder[ "Billing Phone" ],
					"country_code" => $srcOrder[ "Billing Country Code" ],
				]
			];

			$shopOrder[ "billing_address" ] = [
				"first_name" => $srcOrder[ "Billing First" ],
				"last_name" => $srcOrder[ "Billing Last" ],
				"address1" => $srcOrder[ "Billing Address" ],
				"city" => $srcOrder[ "Billing City" ],
				"province" => $srcOrder[ "Billing Province Code" ],
				"zip" => $srcOrder[ "Billing Zip" ],
				"phone" => $srcOrder[ "Billing Phone" ],
				"country_code" => $srcOrder[ "Billing Country Code" ],
			];

			$shopOrder[ "shipping_address" ] = [
				"first_name" => $srcOrder[ "Shipping First" ],
				"address1" => $srcOrder[ "Shipping Last" ],
				"phone" => $srcOrder[ "Shipping Phone" ],
				"city" => $srcOrder[ "Shipping City" ],
				"zip" => $srcOrder[ "Shipping Zip" ],
				"province" => $srcOrder[ "Shipping Province Code" ],
				"last_name" => $srcOrder[ "Shipping Last" ],
				"country_code" => $srcOrder[ "Shipping Country Code" ],
			];

			$shopOrder[ "shipping_lines" ] = [
				[
					"title" => $srcOrder[ "Shipping Method" ],
					"price" => $srcOrder[ "Shipping Price" ],
					"code" => $srcOrder[ "Shipping Method" ],
				]
			];

			$shopOrder[ "line_items" ] = collect( $item )->map( function ( $row ) use ( &$shopOrder ) {
				$shopOrder[ "total_tax" ] = $row[ "Tax" ];
				return [
					"title" => $row[ "Item Name" ],
					"quantity" => $row[ "Item Quantity" ],
					"price" => $row[ "Item Price" ],
					"sku" => $row[ "Item SKU" ],
					"fulfillment_service" => "manual",
					"requires_shipping" => true,
					"taxable" => true,
					"product_exists" => false,
#					"fulfillment_status" => "fulfilled",
				];
			} );

			$shopOrder[ 'note' ] = $this->makeNote( $srcOrder[ "Magento ID" ] );
			$shopOrder[ 'fulfillments' ][] = $this->makeFulfillments( $srcOrder[ "Magento ID" ] );

			//dd( $shopOrder );

			$result = $this->shopify::Order()->create( json_encode( $shopOrder ) );
			//dd( $shopOrder, $result );
			$resultObj = json_decode( $result, true );
			if ( array_key_exists( 'errors', $resultObj ) ) {
				$this->error( "ERROR : $key Error: $result" );
				//dd( $shopOrder );
			}
			else {
				$oldorder_id = $this->getOldOrderID($srcOrder[ "Magento ID" ]);
				$fulfillment_id = optional( $resultObj['fulfillments'] )[0]['id'];
				$order_id = $resultObj['id'];
				$this->info( "Good: " . $order_id );
				$url = "https://" . env( 'SHOP_API_KEY') . ':' . env( 'SHOP_API_PASSWORD' ) . '@' . env( 'SHOP_DOMAIN' );
				$url2 = $url;
				$url .= "/admin/orders/$order_id/fulfillments/$fulfillment_id/complete.json";
				usleep( 600000 );
				Zttp::post( $url, [] );
				$url2 .= "/admin/orders/$oldorder_id.json";
				Zttp::delete( $url2, [] );
			}
			return true;
		} );

	}

	private function getOldOrderID($magid) {
		$whereClause = '%magento_order_number::'.$magid.'%';
		$getShopify = DB::table('shopify_json_orders')
			->where('text','like',$whereClause)
			->select('*')
			->get()
			->first();



		//return
	}

	private function makeFulfillments ( $mId ) {
		$tracks = DB::table( 'sales_flat_shipment_track as t' )
			->join( 'sales_flat_order as s', 's.entity_id', '=', 't.order_id' )
			->where( 's.increment_id', $mId )
			->select( 't.*' )
			->get();
		return collect( $tracks )->map( function ( $t ) {
			return [ 'tracking_number' => $t->track_number, 'created_at' => $t->created_at, 'status' => 'success' ];
		} )->first();
	}

	private function makeNote( $mId ) {

		return DB::table( 'sales_flat_order_status_history' )
			->join( 'sales_flat_order', "sales_flat_order_status_history.parent_id", "=", "sales_flat_order.entity_id" )
			->where( "sales_flat_order.increment_id", "=", $mId )
			->whereNotNull( "comment" )
			->select( "sales_flat_order_status_history.comment" )
			->orderByDesc( "sales_flat_order_status_history.created_at" )
			->get()
			->implode( "comment", "\n\n" );
	}
}
