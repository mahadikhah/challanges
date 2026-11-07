<?php

/*
|--------------------------------------------------------------------------
| Check-in Phrase Vocabulary
|--------------------------------------------------------------------------
|
| The words `text_autogen` challenges draw on to build the phrase a
| participant types to prove a period. Server-only — deliberately absent from
| `localization.client_groups`, because there is no reason to ship the bank to
| a browser.
|
| Two rules for anything added here, both enforced by a test:
|
| 1. Every entry must already equal its own `CheckIn::normalisePhrase()` form:
|    lowercase, single-spaced, ASCII digits, no ZWNJ, Persian ی and ک rather
|    than the Arabic ي and ك. A phrase is stored as drawn, so an unnormalised
|    word would make the stored phrase and the accepted input disagree.
| 2. Words must survive being read off a screen and retyped on a phone
|    keyboard. Nothing long, nothing rare, nothing easily confused.
|
| `:number` is a plain integer in every locale. Farsi keyboards emit ۰-۹, but
| the normaliser folds those to ASCII, so a Farsi participant may type either
| and the stored form stays canonical.
|
*/

return [

    'template' => ':adjective :noun :number',

    'adjectives' => [
        'amber', 'ancient', 'brave', 'bright', 'calm', 'clever', 'copper', 'crimson',
        'distant', 'eager', 'early', 'fearless', 'gentle', 'golden', 'happy', 'hidden',
        'honest', 'humble', 'keen', 'kind', 'lively', 'loyal', 'lucky', 'merry',
        'mighty', 'noble', 'patient', 'polite', 'proud', 'quick', 'quiet', 'ready',
        'sharp', 'silent', 'silver', 'smooth', 'steady', 'sunny', 'warm', 'wise',
    ],

    'nouns' => [
        'anchor', 'arrow', 'autumn', 'beacon', 'bridge', 'candle', 'canyon', 'cedar',
        'cliff', 'comet', 'compass', 'coral', 'crystal', 'dawn', 'delta', 'ember',
        'falcon', 'feather', 'fern', 'forest', 'fountain', 'garden', 'harbour', 'harvest',
        'heron', 'island', 'jasmine', 'lantern', 'ledger', 'lily', 'lotus', 'meadow',
        'mirror', 'morning', 'mountain', 'orchard', 'otter', 'painter', 'pebble', 'planet',
        'prairie', 'quarry', 'ribbon', 'river', 'saddle', 'sailor', 'sparrow', 'spring',
        'summit', 'sunrise', 'temple', 'thunder', 'timber', 'valley', 'village', 'violet',
        'whistle', 'willow', 'window', 'winter',
    ],

];
