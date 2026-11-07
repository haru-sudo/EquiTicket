<?php
/**
 * 身長・体重・年齢から乗れる馬を振り分ける処理。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 参加条件の判定と、乗る馬の振り分け。
 *
 * 馬ごとの条件（年齢・身長・体重の下限と上限）は「馬の種類」画面で登録する
 * （EquiTicket_Horses）。ここでは全体の条件（最低年齢・保護者同意が必要な年齢）と、
 * 入力された体格に合う馬の一覧を求める。
 */
final class EquiTicket_Screening {

	/**
	 * 設定が読めない場合に使う判定基準の初期値。
	 *
	 * @return array<string,int> 判定基準。
	 */
	public static function default_limits() {
		return array(
			'min_age'   => 5,
			'minor_age' => 18,
		);
	}

	/**
	 * 設定値から判定基準を取り出す。
	 *
	 * @return array<string,int> 判定基準。
	 */
	public static function limits() {
		return array(
			'min_age'   => (int) EquiTicket_Options::get( 'min_age' ),
			'minor_age' => (int) EquiTicket_Options::get( 'minor_age' ),
		);
	}

	/**
	 * 馬の表示名。
	 *
	 * @param int $horse_id 馬の種類の ID。
	 * @return string 表示名。
	 */
	public static function type_label( $horse_id ) {
		$horse = EquiTicket_Horses::get( $horse_id );

		return $horse ? $horse->name : __( '馬', 'equiticket' );
	}

	/**
	 * 参加できる体重の上限（いずれかの馬に乗れる上限）。
	 *
	 * @param array<int,object>|null $horses 馬の種類（省略時は受付中のもの）。
	 * @return int 体重の上限（kg）。上限のない馬があれば 0。
	 */
	public static function max_weight( $horses = null ) {
		$horses = null === $horses ? EquiTicket_Horses::all( true ) : $horses;
		$max    = 0;
		foreach ( $horses as $horse ) {
			if ( $horse->max_weight <= 0 ) {
				return 0;
			}
			$max = max( $max, $horse->max_weight );
		}

		return $max;
	}

	/**
	 * 入力された身体情報から、乗れる馬を判定する。
	 *
	 * 登録されている馬それぞれの条件を調べ、乗れるものをすべて返す。2 つ以上なら本人に選んでもらう。
	 * $offered を渡すと、実際に時間枠が用意されている馬だけに絞る
	 * （1 日目はポニーと馬、2・3 日目はポニーとミニチュアホース、のような日程に合わせるため）。
	 *
	 * @param mixed                  $height  身長（cm）。
	 * @param mixed                  $weight  体重（kg）。
	 * @param mixed                  $age     年齢。
	 * @param array<int,object>|null $horses  馬の種類（省略時は受付中のもの）。
	 * @param array<int,int>|null    $offered 時間枠が用意されている馬の ID（省略時は絞らない）。
	 * @param array<string,int>|null $limits  全体の判定基準（省略時は設定値）。
	 * @return array<string,mixed>|WP_Error 判定結果、または参加できない理由。
	 */
	public static function assign( $height, $weight, $age, $horses = null, $offered = null, $limits = null ) {
		$limits = is_array( $limits ) ? array_merge( self::default_limits(), $limits ) : self::limits();
		$horses = null === $horses ? EquiTicket_Horses::all( true ) : $horses;

		if ( ! is_numeric( $height ) || ! is_numeric( $weight ) || ! is_numeric( $age ) ) {
			return new WP_Error( 'equiticket_profile_required', __( '身長・体重・年齢を数字で入力してください。', 'equiticket' ) );
		}

		$height = (int) round( (float) $height );
		$weight = (int) round( (float) $weight );
		$age    = (int) $age;

		if ( $height < 50 || $height > 250 ) {
			return new WP_Error( 'equiticket_height_range', __( '身長は 50〜250cm の範囲で入力してください。', 'equiticket' ) );
		}
		if ( $weight < 5 || $weight > 200 ) {
			return new WP_Error( 'equiticket_weight_range', __( '体重は 5〜200kg の範囲で入力してください。', 'equiticket' ) );
		}
		if ( $age < 0 || $age > 120 ) {
			return new WP_Error( 'equiticket_age_range', __( '年齢は 0〜120 の範囲で入力してください。', 'equiticket' ) );
		}

		if ( $age < $limits['min_age'] ) {
			return new WP_Error(
				'equiticket_too_young',
				sprintf(
					/* translators: %d: 参加できる最低年齢 */
					__( '安全のため、%d 歳以上の方を対象としています。', 'equiticket' ),
					$limits['min_age']
				)
			);
		}

		$max_weight = self::max_weight( $horses );
		if ( $max_weight > 0 && $weight > $max_weight ) {
			return new WP_Error(
				'equiticket_too_heavy',
				sprintf(
					/* translators: %d: 参加できる体重の上限 */
					__( '馬の負担を考え、体重 %dkg までの方を対象としています。', 'equiticket' ),
					$max_weight
				)
			);
		}

		$offered = is_array( $offered ) ? array_map( 'intval', $offered ) : null;
		$options = array();
		$names   = array();
		foreach ( $horses as $horse ) {
			if ( ! EquiTicket_Horses::accepts( $horse, $height, $weight, $age ) ) {
				continue;
			}
			// 時間枠がひとつもない馬は案内しない（枠がまったくない場合は絞らない）。
			if ( $offered && ! in_array( (int) $horse->id, $offered, true ) ) {
				continue;
			}
			$options[]              = (int) $horse->id;
			$names[ (int) $horse->id ] = $horse->name;
		}

		if ( ! $options ) {
			return new WP_Error(
				'equiticket_no_match',
				__( '入力いただいた体格に合う馬をご用意できませんでした。当日、会場のスタッフにご相談ください。', 'equiticket' )
			);
		}

		if ( 1 === count( $options ) ) {
			/* translators: %s: 馬の種類 */
			$message = sprintf( __( '体格に合わせて、%sでご案内します。', 'equiticket' ), $names[ $options[0] ] );
		} elseif ( 2 === count( $options ) ) {
			$message = sprintf(
				/* translators: 1: 馬の種類, 2: 馬の種類 */
				__( '%1$sと%2$sのどちらでもご案内できます。乗りたい馬をお選びください。', 'equiticket' ),
				$names[ $options[0] ],
				$names[ $options[1] ]
			);
		} else {
			$message = sprintf(
				/* translators: %s: 馬の種類の並び（「ポニー・馬・ミニチュアホース」など） */
				__( '%s のどれでもご案内できます。乗りたい馬をお選びください。', 'equiticket' ),
				implode( '・', $names )
			);
		}

		return array(
			'options'           => $options,
			'names'             => $names,
			'type'              => $options[0],
			'choice_required'   => ( count( $options ) > 1 ),
			'label'             => $names[ $options[0] ],
			'height_cm'         => $height,
			'weight_kg'         => $weight,
			'age'               => $age,
			'is_minor'          => ( $age < $limits['minor_age'] ),
			'requires_guardian' => ( $age < $limits['minor_age'] ),
			'message'           => $message,
		);
	}

	/**
	 * 判定結果のなかから、希望された馬を確定させる。
	 *
	 * @param array<string,mixed> $profile   判定結果。
	 * @param mixed               $requested 希望する馬の ID。
	 * @return int|null 確定した馬の ID。選択が必要な場合は null。
	 */
	public static function resolve_type( array $profile, $requested = 0 ) {
		$requested = is_numeric( $requested ) ? (int) $requested : 0;
		if ( $requested > 0 && in_array( $requested, $profile['options'], true ) ) {
			return $requested;
		}

		return ( 1 === count( $profile['options'] ) ) ? $profile['options'][0] : null;
	}
}
