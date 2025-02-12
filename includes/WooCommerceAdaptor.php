<?php

namespace WOOTB\includes;


use WC_Customer;

class WooCommerceAdaptor {
	public $order;
	public $order_id;

	protected $pattern = '/\{([\w.]++)}/m';

	function __construct( $order_id ) {
		$this->order    = new \WC_Order( $order_id );
		$this->order_id = $this->order->get_id();

	}

	public function interpolate( $message ) {
		$detail = $this->order_extra_detail( $message );
		$detail = $this->order_detail( $detail );
		$detail = $this->product_detail( $detail );
		if ( ! trim( $detail['products'] ) ) {
			return null;
		}
		$detail = apply_filters( 'wootb_message_interpolate', $detail, $this->order_id );
		foreach ( $detail as $key => $val ) {
			$message = str_replace( '{' . $key . '}', $val, $message );
		}
		$message = preg_replace( $this->pattern, '', $message );

		return $message;
	}

	private function order_extra_detail( $message ) {
		preg_match_all( $this->pattern, $message, $matches, PREG_PATTERN_ORDER );
		$matches = array_unique( $matches[1] );
		$data    = $this->order->get_data();
		$detail  = [];
		foreach ( $matches as $match ) {
			if ( isset( $detail[ $match ] ) ) {
				continue;
			}
			$path = preg_replace( '/^order\./', '', $match );
			$path = explode( '.', $path );
			if ( isset( $data[ $path[0] ] ) ) {
				$value = $data;
				foreach ( $path as $key ) {
					if ( ! isset( $value[ $key ] ) ) {
						$value = '';
						break;
					}
					$value = $value[ $key ];
				}
				if ( is_array( $value ) || is_object( $value ) ) {
					$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
				} else {
					$value = __( $value );
				}
			}
			$detail[ $match ] = $value ?? '';
		}

		return $detail;
	}

	function order_detail( $replace ) {
		$date                              = $this->order->get_date_created() ?? $this->order->get_date_modified();
		$date                              = isset( $date ) ? $date->date( get_option( 'links_updated_date_format' ) ) : '-';
		$meta_data                         = $this->order->get_meta_data();
		$replace['shop.url']               = get_option( 'siteurl', sanitize_text_field( $_SERVER['HTTP_HOST'] ) );
		$replace['shop.name']              = get_option( 'blogname', "blog" );
		$replace['shop.tag']               = preg_replace( '/\W/', '', get_option( 'blogname', "blog" ) );
		$replace['customer.id']            = $this->order->get_user_id();
		$replace['customer.order_count']   = $this->wcGetCustomerOrderCount();
		$replace['order.id']               = $this->order_id;
		$replace['order.edit_url']         = $this->order->get_edit_order_url();
		$replace['order.status']           = wc_get_order_status_name( $this->order->get_status() );
		$replace['order.notes']            = $this->order->get_customer_note();
		$replace['order.icon']             = [
			                                     'processing' => '🕙',
			                                     'completed'  => '✅',
			                                     'cancelled'  => '❌',
			                                     'refunded'   => '💸',
			                                     'pending'    => '💳',
			                                     'on-hold'    => '📥'
		                                     ][ $this->order->get_status() ] ?? '🛒';
		$replace['total']                  = $this->format_price( $this->order->get_total() );
		$replace['order.date_created']     = $date;
		$replace['order.date_created_per'] = PersianDate::jdate( 'd F Y, g:i a', strtotime( $date ) );
		$replace['shipping.method_title']  = $replace['shipping.method'] = $this->order->get_shipping_method();
		$replace['shipping.total']         = $this->format_price( $this->order->get_shipping_total() );
		$replace['payment.method_title']   = $replace['payment.method'] = $this->order->get_payment_method();
		$order_meta                        = '';
		foreach ( $meta_data as $object ) {
			$order_meta                         .= $object->key . " : " . $object->value . "\n";
			$replace["order.meta.$object->key"] = $object->value;
		}
		$replace['order.meta']      = $order_meta;
		$replace['order.source']    = __( $replace['order.meta._wc_order_attribution_source_type'], 'notify-bot-woocommerce' ) . ' : ' . __( $replace['order.meta._wc_order_attribution_utm_source'], 'notify-bot-woocommerce' );
		$replace['customer.is_old'] = sprintf( __( $replace['customer.order_count'] ? 'Old (%1$s)' : 'New', 'notify-bot-woocommerce' ), $replace['customer.order_count'] );

		return $replace;
	}

	function wcGetCustomerOrderCount() {
		$count = "";
		try {
			$customer = new WC_Customer( $this->order->get_user_id() );
			$count    = $customer->get_order_count();
		} catch ( \Exception $e ) {
		}

		return $count ?? "";
	}

	public function get_status() {
		return $this->order->get_status();
	}

	function format_price( $price ) {
		return str_replace( '&nbsp;', ' ', wc_price( $price ) );
	}

	private function product_detail( $detail ): array {
		$link_mode    = get_option( 'wootb_link_mode', 'none' );
		$items        = $this->order->get_items();
		$product_meta = "";
		$product      = chr( 10 );
		$url          = get_option( 'siteurl', sanitize_text_field( $_SERVER['HTTP_HOST'] ) );
		if ( ! empty( $items ) ) {
			foreach ( $items as $item ) {
				$product_item = $item->get_product();
				if ( $product_item ) {
					switch ( $link_mode ) {
						case 'edit':
							$link = [
								"<a href=\"$url/wp-admin/post.php?post={$item->get_product_id()}&action=edit\">",
								'</a>'
							];
							break;
						case 'view':
							$link = [ '<a href="' . get_permalink( $item->get_product_id() ) . '">', '</a>' ];
							break;
						default:
							$link = [ '', '' ];
					}
					$price     = $this->format_price( wc_get_price_to_display( $product_item ) );
					$title     = is_rtl() ? str_replace( ' - ', '‏ - ', $item->get_name() ) : $item->get_name();
					$product   .= $link[0] . $title . $link[1] . ' : ' . sprintf( __( '%1$sQty x %2$s', 'notify-bot-woocommerce' ), $item->get_quantity(), $price ) . "\n";
					$item_meta = $item->get_meta_data();
					if ( $item_meta ) {
						if ( is_array( $item_meta ) ) {
							foreach ( $item_meta as $object ) {
								$product_meta                        .= $object->key . " : " . $object->value . "\n";
								$detail["product.meta.$object->key"] = $object->value;
							}
							$detail['product.meta'] = $product_meta;
						}
					}
				}
			}
		}
		$detail['products'] = $product;

		return $detail;
	}
}