<?php

namespace App\Enums\Contracts;

/**
 * The label contract: a backed enum with a translated, user-facing name.
 *
 * An interface, deliberately, and not just the `Concerns\HasTranslatedLabel`
 * trait: only interfaces can appear in native and PHPDoc type positions, so
 * "give me something with a label" can be an actual parameter type — the
 * Mini App resource's enum shape below the fold is why this exists. The
 * implementation stays in the trait; enums declare `implements` and `use`
 * together.
 */
interface HasTranslatedLabel
{
    /**
     * Translated name, resolving in the ambient locale. Queue-bound callers
     * must prefer `translationKey()` — see its docblock for why.
     */
    public function label(): string;

    /**
     * The catalogue key behind `label()`, so per-recipient resolution can
     * happen outside a request's locale.
     */
    public function translationKey(): string;
}
