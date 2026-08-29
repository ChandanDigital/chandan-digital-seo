<?php
/**
 * Entity Schema Generator.
 *
 * Builds one clean JSON-LD block for the site's Organization / LocalBusiness
 * entity, merging the Knowledge Panel fields with the Local SEO fields.
 * This is the single source of truth for entity-level schema, so it
 * replaces the old separate LocalBusiness output and stops duplicate
 * schema blocks from going into the page source.
 *
 * @package SEO_Manager
 */

if (!defined('ABSPATH')) exit;

final class SEO_Manager_Entity_Schema {

    /**
     * Keeps the block from being printed twice on the same request.
     *
     * @var bool
     */
    private static bool $printed = false;

    /**
     * Hooks the output into wp_head. Runs once per request, on every
     * page (not just singular posts), because Organization/LocalBusiness
     * data describes the whole site, not one piece of content.
     */
    public static function init(): void {
        add_action('wp_head', [__CLASS__, 'output'], 4);
    }

    /**
     * Prints the merged JSON-LD block.
     */
    public static function output(): void {
        if (self::$printed) {
            return;
        }

        $data = self::build();

        if (empty($data)) {
            return;
        }

        self::$printed = true;

        echo '<script type="application/ld+json">'
            . wp_json_encode($data, JSON_UNESCAPED_UNICODE)
            . '</script>' . "\n";
    }

    /**
     * Puts together the full schema array. Public and static so other
     * parts of the plugin (or a unit test) can pull the array without
     * triggering output.
     *
     * @return array<string,mixed>
     */
    public static function build(): array {
        $is_local = (int) seom_get('local_enabled', 0) === 1;

        $name = seom_get('knowledge_entity_name', '') ?: get_bloginfo('name');
        if ($name === '') {
            // Nothing meaningful to build a schema from.
            return [];
        }

        $type = $is_local
            ? (seom_get('local_business_type', 'LocalBusiness') ?: 'LocalBusiness')
            : (seom_get('knowledge_entity_type', 'Organization') ?: 'Organization');

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => sanitize_text_field($type),
            '@id'      => home_url('/#organization'),
            'name'     => $name,
            'url'      => home_url('/'),
        ];

        $description = seom_get('knowledge_entity_description', '');
        if ($description !== '') {
            $data['description'] = $description;
        }

        $logo = seom_get('knowledge_entity_logo', '') ?: seom_get('org_logo', '');
        if ($logo !== '') {
            $data['logo'] = [
                '@type' => 'ImageObject',
                'url'   => $logo,
            ];
            // Many validators also read a plain "image" property.
            $data['image'] = $logo;
        }

        // --- sameAs: dedicated social fields + bulk textarea, merged and de-duplicated.
        $same_as = self::build_same_as();
        if (!empty($same_as)) {
            $data['sameAs'] = $same_as;
        }

        // --- Knowledge Graph entity ID (kg:/... form).
        $entity_id = seom_get('knowledge_entity_id', '');
        if ($entity_id !== '') {
            $data['identifier'] = $entity_id;
            // Google's public Knowledge Panel URL uses the kgmid parameter
            // without the leading "kg:" prefix, so surface it in sameAs too.
            $kgmid = preg_replace('#^kg:#', '', $entity_id);
            if ($kgmid !== '') {
                $data['sameAs'][] = 'https://www.google.com/search?kgmid=' . rawurlencode($kgmid);
                $data['sameAs']   = array_values(array_unique($data['sameAs']));
            }
        }

        // --- Google Place ID + Maps URL.
        $maps_url = seom_get('knowledge_google_maps_url', '');
        if ($maps_url !== '') {
            $data['hasMap'] = $maps_url;
        }

        $place_id = seom_get('knowledge_place_id', '');
        if ($place_id !== '') {
            $data['additionalProperty'][] = [
                '@type' => 'PropertyValue',
                'name'  => 'Google Place ID',
                'value' => $place_id,
            ];
        }

        // --- Advanced Knowledge Graph fields.
        $founder = seom_get('knowledge_founder', '');
        if ($founder !== '') {
            $data['founder'] = [
                '@type' => 'Person',
                'name'  => $founder,
            ];
        }

        $founding_date = seom_get('knowledge_founding_date', '');
        if ($founding_date !== '' && preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $founding_date)) {
            $data['foundingDate'] = $founding_date;
        }

        $corporate_phone = seom_get('knowledge_contact_phone', '');
        if ($corporate_phone !== '') {
            $data['contactPoint'][] = [
                '@type'       => 'ContactPoint',
                'telephone'   => $corporate_phone,
                'contactType' => 'customer support',
            ];
        }

        // --- Local SEO fields, only nested in when the site is a Local Business.
        if ($is_local) {
            $data = self::merge_local_fields($data);
        }

        return $data;
    }

    /**
     * Merges the dedicated social profile fields with the bulk "SameAs
     * URLs" textarea into one clean, de-duplicated list.
     *
     * @return string[]
     */
    private static function build_same_as(): array {
        $urls = [];

        $social_fields = [
            'knowledge_social_facebook',
            'knowledge_social_twitter',
            'knowledge_social_instagram',
            'knowledge_social_linkedin',
            'knowledge_social_youtube',
        ];

        foreach ($social_fields as $field) {
            $url = trim((string) seom_get($field, ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        $bulk = (string) seom_get('knowledge_entity_sameas', '');
        if ($bulk !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $bulk) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $urls[] = $line;
                }
            }
        }

        // Keep only well-formed URLs and drop duplicates while preserving order.
        $urls = array_filter($urls, static function ($url) {
            return (bool) wp_http_validate_url($url);
        });

        return array_values(array_unique($urls));
    }

    /**
     * Folds the existing Local SEO option fields (phone, address, geo,
     * hours) into the schema array. Keeping this as one method makes it
     * obvious this is the only place Local SEO data reaches the JSON-LD
     * output, so nothing gets printed twice.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function merge_local_fields(array $data): array {
        $phone = seom_get('local_phone', '');
        if ($phone !== '') {
            $data['telephone'] = $phone;
        }

        $email = seom_get('local_email', '');
        if ($email !== '') {
            $data['email'] = $email;
        }

        $price_range = seom_get('local_price_range', '');
        if ($price_range !== '') {
            $data['priceRange'] = $price_range;
        }

        $address = array_filter([
            'streetAddress'   => seom_get('local_address', ''),
            'addressLocality' => seom_get('local_city', ''),
            'addressRegion'   => seom_get('local_state', ''),
            'postalCode'      => seom_get('local_postcode', ''),
            'addressCountry'  => seom_get('local_country', ''),
        ]);
        if (!empty($address)) {
            $data['address'] = array_merge(['@type' => 'PostalAddress'], $address);
        }

        $lat = seom_get('local_lat', '');
        $lng = seom_get('local_lng', '');
        if ($lat !== '' && $lng !== '') {
            $data['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $lat,
                'longitude' => (float) $lng,
            ];
        }

        $hours = seom_get('local_hours', '');
        if ($hours !== '') {
            // A comma or semicolon separated string becomes an array of
            // openingHours entries; a single entry stays a plain string.
            $parts = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $hours))));
            $data['openingHours'] = count($parts) > 1 ? $parts : $hours;
        }

        return $data;
    }
}
