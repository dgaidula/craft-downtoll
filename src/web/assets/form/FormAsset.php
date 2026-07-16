<?php

namespace dgaidula\downtoll\web\assets\form;

use craft\web\AssetBundle;

/**
 * The shipped front-end behaviour for the `render()` form: reCAPTCHA token,
 * the JSON submit round-trip, inline errors, swap/reload success, and the
 * affiliation → district toggle.
 *
 * Registered by {@see \dgaidula\downtoll\web\twig\DowntollVariable::render()},
 * so ONLY the turnkey `render()` path pulls it in — headless `data()` users
 * bring their own front end. Plain ES, no build step.
 */
class FormAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $js = ['downtoll-form.js'];
}
