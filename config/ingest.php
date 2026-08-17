<?php

/**
 * Default field mappings per ingest source (Z-5.6) — used until an admin
 * customises a source through the interface (S-5.2's field-mapping editor), at
 * which point App\Support\Ingest\FieldMapRepository reads from `settings` instead.
 * Every mapping targets the `leads` module: WordPress forms and Meta Lead Ads both
 * capture leads.
 *
 * WordPress note: the four supported plugins (Gravity Forms, Contact Form 7,
 * WPForms, Elementor) each nest their webhook payload differently —
 * App\Support\Ingest\Sources\WordPressPayloadNormalizer flattens the plugin-
 * specific structure into a flat array *before* this map applies, using each
 * plugin's own field label/id as the flattened key where the plugin exposes one
 * (e.g. Contact Form 7's own `your-name`/`your-email` convention), falling back to
 * a numbered `field_N` key otherwise. These defaults cover the common-label case;
 * a real form's actual field ids/labels should be confirmed against a live
 * payload and adjusted via the settings-backed override.
 */
return [

    // Bumped independently of the app's own releases as Meta deprecates old
    // versions — see https://developers.facebook.com/docs/graph-api/changelog.
    'meta' => [
        'graph_version' => 'v21.0',
    ],

    'field_maps' => [

        // `leads.full_name` has no real backing column or mutator (it's read via
        // Contactable::fullName(), a plain method Eloquent's mass-assignment can't
        // reach) — targeting the real first_name/last_name columns instead. A web
        // form's "Name" field arrives as one string, so both rules read the same
        // source field and split it with the name_first/name_last transforms.
        'wordpress' => [
            ['source_field' => 'your-name', 'target_field' => 'first_name', 'transform' => 'name_first'],
            ['source_field' => 'your-name', 'target_field' => 'last_name', 'transform' => 'name_last'],
            ['source_field' => 'name', 'target_field' => 'first_name', 'transform' => 'name_first'],
            ['source_field' => 'name', 'target_field' => 'last_name', 'transform' => 'name_last'],
            ['source_field' => 'your-email', 'target_field' => 'primary_email', 'transform' => 'lower'],
            ['source_field' => 'email', 'target_field' => 'primary_email', 'transform' => 'lower'],
            ['source_field' => 'your-phone', 'target_field' => 'phone_mobile', 'transform' => 'phone'],
            ['source_field' => 'phone', 'target_field' => 'phone_mobile', 'transform' => 'phone'],
            ['source_field' => 'your-message', 'target_field' => 'description'],
            ['source_field' => 'message', 'target_field' => 'description'],
            ['source_field' => 'source', 'target_field' => 'source', 'default' => 'wordpress'],
        ],

        // Meta's own Graph API leadgen field names — field_data[].name, per
        // https://developers.facebook.com/docs/marketing-api/guides/lead-ads —
        // first_name/last_name are Meta's own standard field names already, no
        // splitting needed.
        'meta' => [
            ['source_field' => 'first_name', 'target_field' => 'first_name'],
            ['source_field' => 'last_name', 'target_field' => 'last_name'],
            ['source_field' => 'full_name', 'target_field' => 'first_name', 'transform' => 'name_first'],
            ['source_field' => 'email', 'target_field' => 'primary_email', 'transform' => 'lower'],
            ['source_field' => 'phone_number', 'target_field' => 'phone_mobile', 'transform' => 'phone'],
            ['source_field' => 'source', 'target_field' => 'source', 'default' => 'meta'],
        ],
    ],
];
