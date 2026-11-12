<?php
/**
 * Decides whether an incoming chat lead is someone AJCore already has. Pure functions, no WordPress calls.
 *
 * Rules (the same keys AJCore's own duplicate tool uses, plus business identity):
 *   1. same email;
 *   2. else same phone (last 10 digits);
 *   3. else same business name AND same contact name. A business name alone is not enough: two different
 *      people can work for one company.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AJMCP_WP_Lead_Match {
    public static function email( $value ): string {
        $email = strtolower( trim( (string) $value ) );
        return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
    }

    /** Last 10 digits of a US-style number, or '' when there are fewer than 10 digits. */
    public static function phone_key( $value ): string {
        $digits = preg_replace( '/\D+/', '', (string) $value );
        if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
            $digits = substr( $digits, 1 );
        }
        return 10 === strlen( $digits ) ? $digits : '';
    }

    /** "Lux Venturez, L.L.C." and "lux venturez llc" share a key. */
    public static function company_key( $value ): string {
        $text = strtolower( trim( (string) $value ) );
        $text = str_replace( '&', ' and ', $text );
        $text = preg_replace( '/\b([a-z])\.\s*(?=[a-z]\.)/', '$1', $text );
        $text = preg_replace( '/[^a-z0-9 ]+/', ' ', $text );
        $text = preg_replace( '/\b(llc|l l c|pllc|inc|incorporated|corp|corporation|co|company|ltd|limited|lp|llp)\b/', ' ', $text );
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        return strlen( $text ) >= 2 ? $text : '';
    }

    public static function name_key( $value ): string {
        return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9 ]+/', ' ', strtolower( (string) $value ) ) ) );
    }

    /**
     * @param array $incoming   { name, email, phone, company }
     * @param array $candidates rows of { id, name, email, phone, company }, oldest first.
     * @return array|null       { id, by } of the lead to update, or null when this is a new lead.
     */
    public static function find( array $incoming, array $candidates ): ?array {
        $email   = self::email( $incoming['email'] ?? '' );
        $phone   = self::phone_key( $incoming['phone'] ?? '' );
        $company = self::company_key( $incoming['company'] ?? '' );
        $name    = self::name_key( $incoming['name'] ?? '' );

        if ( '' !== $email ) {
            foreach ( $candidates as $c ) {
                if ( self::email( $c['email'] ?? '' ) === $email ) {
                    return array( 'id' => (int) $c['id'], 'by' => 'email' );
                }
            }
        }
        if ( '' !== $phone ) {
            foreach ( $candidates as $c ) {
                if ( self::phone_key( $c['phone'] ?? '' ) === $phone ) {
                    return array( 'id' => (int) $c['id'], 'by' => 'phone' );
                }
            }
        }
        if ( '' !== $company && '' !== $name ) {
            foreach ( $candidates as $c ) {
                if ( self::company_key( $c['company'] ?? '' ) === $company && self::name_key( $c['name'] ?? '' ) === $name ) {
                    return array( 'id' => (int) $c['id'], 'by' => 'company' );
                }
            }
        }
        return null;
    }
}
