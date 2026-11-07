<?php

use App\Actions\CheckIns\IssueCheckInPhrase;
use App\Enums\ProofType;
use App\Exceptions\PhraseUnavailableException;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use App\Services\Localization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->issue = app(IssueCheckInPhrase::class);
});

/**
 * A challenge whose proof is a typed phrase.
 */
function phraseChallenge(): Challenge
{
    return Challenge::factory()->active()->provenBy(ProofType::TextAutogen)->create();
}

/**
 * A participant on `$challenge`, reading `$locale` if one is given.
 */
function reader(Challenge $challenge, ?string $locale = null, ?string $languageCode = null): ChallengeParticipant
{
    $user = User::factory()->telegram()->create([
        'locale' => $locale,
        'language_code' => $languageCode,
    ]);

    return ChallengeParticipant::factory()->for($challenge)->for($user)->create();
}

/**
 * An open period on `$challenge`'s timeline.
 */
function livePeriod(Challenge $challenge, int $index = 0): ChallengePeriod
{
    return ChallengePeriod::factory()->for($challenge)->atIndex($index)->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);
}

/**
 * Replace a locale's whole vocabulary. `addLines` marks the group loaded, so the
 * real file is never read and only what is passed here exists.
 *
 * @param  array<string, mixed>  $lines
 */
function vocabulary(array $lines, string $locale = 'en'): void
{
    Lang::addLines($lines, $locale);
}

describe('issuing', function () {
    it('gives a text_autogen check-in a phrase and persists it', function () {
        $challenge = phraseChallenge();
        $checkIn = $this->issue->forParticipant(reader($challenge, 'en'), livePeriod($challenge));

        expect($checkIn->expected_phrase)->toBeString()
            ->and($checkIn->expected_phrase)->not->toBeEmpty()
            ->and($checkIn->fresh()->expected_phrase)->toBe($checkIn->expected_phrase);
    });

    it('draws an adjective, a noun and a two-digit number', function () {
        $bank = require lang_path('en/phrases.php');

        $phrase = $this->issue->generate('en');

        expect($phrase)->toMatch('/^[a-z]+ [a-z]+ [1-9][0-9]$/');

        [$adjective, $noun] = explode(' ', $phrase);

        expect($bank['adjectives'])->toContain($adjective)
            ->and($bank['nouns'])->toContain($noun);
    });

    it('opens the obligation for a participant who never touched it', function () {
        $challenge = phraseChallenge();
        $participant = reader($challenge, 'en');
        $period = livePeriod($challenge);

        expect(CheckIn::query()->count())->toBe(0);

        $checkIn = $this->issue->forParticipant($participant, $period);

        expect(CheckIn::query()->count())->toBe(1)
            ->and($checkIn->challenge_participant_id)->toBe($participant->id)
            ->and($checkIn->challenge_period_id)->toBe($period->id)
            ->and($checkIn->expected_phrase)->not->toBeNull();
    });

    it('writes no phrase for a proof type that does not ask for one', function (ProofType $proofType) {
        $challenge = Challenge::factory()->active()->provenBy($proofType)->create();

        $checkIn = $this->issue->forParticipant(reader($challenge, 'en'), livePeriod($challenge));

        expect($checkIn->expected_phrase)->toBeNull()
            ->and($checkIn->fresh()->expected_phrase)->toBeNull();
    })->with([
        'button' => ProofType::Button,
        'image approval' => ProofType::ImageApproval,
    ]);

    it('leaves an already-settled row alone', function () {
        $challenge = phraseChallenge();
        $checkIn = CheckIn::factory()->on(reader($challenge, 'en'), livePeriod($challenge))->missed()->create();

        $this->issue->handle($checkIn);

        expect($checkIn->fresh()->expected_phrase)->toBeNull();
    });
});

describe('stability', function () {
    it('never re-rolls a phrase it has already issued', function () {
        $challenge = phraseChallenge();
        $checkIn = $this->issue->forParticipant(reader($challenge, 'en'), livePeriod($challenge));
        $first = $checkIn->expected_phrase;

        $this->issue->handle($checkIn);
        $this->issue->handle($checkIn->fresh());

        expect($checkIn->fresh()->expected_phrase)->toBe($first);
    });

    it('re-opening the obligation returns the same row and the same phrase', function () {
        $challenge = phraseChallenge();
        $participant = reader($challenge, 'en');
        $period = livePeriod($challenge);

        $first = $this->issue->forParticipant($participant, $period);
        $second = $this->issue->forParticipant($participant, $period);

        expect($second->id)->toBe($first->id)
            ->and($second->expected_phrase)->toBe($first->expected_phrase)
            ->and(CheckIn::query()->count())->toBe(1);
    });

    it('keeps the phrase it issued after the participant switches language', function () {
        $challenge = phraseChallenge();
        $participant = reader($challenge, 'en');
        $checkIn = $this->issue->forParticipant($participant, livePeriod($challenge));
        $english = $checkIn->expected_phrase;

        $participant->user->update(['locale' => 'fa']);
        $this->issue->handle($checkIn->fresh());

        expect($checkIn->fresh()->expected_phrase)->toBe($english);
    });
});

describe('distinctness', function () {
    it('gives every participant in a period a different phrase', function () {
        $challenge = phraseChallenge();
        $period = livePeriod($challenge);

        $phrases = collect(range(1, 6))
            ->map(fn (): string => (string) $this->issue
                ->forParticipant(reader($challenge, 'en'), $period)
                ->expected_phrase);

        expect($phrases->unique())->toHaveCount(6);
    });

    it('refuses two identical phrases in the same period', function () {
        $challenge = phraseChallenge();
        $period = livePeriod($challenge);

        CheckIn::factory()->on(reader($challenge), $period)->withPhrase('blue anchor 42')->create();

        expect(fn () => CheckIn::factory()->on(reader($challenge), $period)->withPhrase('blue anchor 42')->create())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('allows the same phrase in a different period', function () {
        $challenge = phraseChallenge();
        $participant = reader($challenge);

        CheckIn::factory()->on($participant, livePeriod($challenge, 0))->withPhrase('blue anchor 42')->create();
        CheckIn::factory()->on($participant, livePeriod($challenge, 1))->withPhrase('blue anchor 42')->create();

        expect(CheckIn::query()->where('expected_phrase', 'blue anchor 42')->count())->toBe(2);
    });

    it('does not let a participant pass with someone else\'s phrase', function () {
        $challenge = phraseChallenge();
        $period = livePeriod($challenge);

        $mine = $this->issue->forParticipant(reader($challenge, 'en'), $period);
        $theirs = $this->issue->forParticipant(reader($challenge, 'en'), $period);

        expect($mine->matchesExpectedPhrase($theirs->expected_phrase))->toBeFalse()
            ->and($mine->matchesExpectedPhrase($mine->expected_phrase))->toBeTrue();
    });

    it('throws rather than reuse a phrase when the vocabulary is exhausted', function () {
        vocabulary([
            'phrases.template' => ':adjective :noun',
            'phrases.adjectives' => ['solo'],
            'phrases.nouns' => ['island'],
        ]);

        $challenge = phraseChallenge();
        $period = livePeriod($challenge);

        expect($this->issue->forParticipant(reader($challenge, 'en'), $period)->expected_phrase)
            ->toBe('solo island');

        expect(fn () => $this->issue->forParticipant(reader($challenge, 'en'), $period))
            ->toThrow(PhraseUnavailableException::class);
    });
});

describe('matching what a participant types', function () {
    it('accepts the phrase however it was retyped', function (callable $retype) {
        $challenge = phraseChallenge();
        $checkIn = $this->issue->forParticipant(reader($challenge, 'en'), livePeriod($challenge));

        expect($checkIn->matchesExpectedPhrase($retype((string) $checkIn->expected_phrase)))->toBeTrue();
    })->with([
        'as issued' => [fn (string $phrase): string => $phrase],
        'shouted' => [fn (string $phrase): string => mb_strtoupper($phrase)],
        'padded' => [fn (string $phrase): string => "  {$phrase}\n"],
        'double-spaced' => [fn (string $phrase): string => str_replace(' ', '  ', $phrase)],
    ]);

    it('accepts a Farsi phrase typed with Persian digits or an Arabic yeh', function () {
        $challenge = phraseChallenge();
        $checkIn = $this->issue->forParticipant(reader($challenge, 'fa'), livePeriod($challenge));
        $phrase = (string) $checkIn->expected_phrase;

        $persianDigits = str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $phrase,
        );

        expect($checkIn->matchesExpectedPhrase($persianDigits))->toBeTrue()
            ->and($checkIn->matchesExpectedPhrase(str_replace('ی', 'ي', $phrase)))->toBeTrue()
            ->and($checkIn->matchesExpectedPhrase(str_replace('ک', 'ك', $phrase)))->toBeTrue();
    });

    it('rejects a near miss', function (string $answer) {
        $challenge = phraseChallenge();
        $checkIn = CheckIn::factory()
            ->on(reader($challenge), livePeriod($challenge))
            ->withPhrase('blue anchor 42')
            ->create();

        expect($checkIn->matchesExpectedPhrase($answer))->toBeFalse();
    })->with([
        'wrong number' => 'blue anchor 43',
        'wrong adjective' => 'green anchor 42',
        'missing number' => 'blue anchor',
        'extra word' => 'blue anchor 42 please',
        'empty' => '',
    ]);
});

describe('locale selection', function () {
    it('issues in the locale the participant chose', function () {
        $challenge = phraseChallenge();
        $checkIn = $this->issue->forParticipant(reader($challenge, 'fa', 'en'), livePeriod($challenge));

        expect((string) $checkIn->expected_phrase)->toMatch('/\p{Arabic}/u');
    });

    it('falls back to the language code Telegram reported', function () {
        $challenge = phraseChallenge();
        $checkIn = $this->issue->forParticipant(reader($challenge, null, 'fa-IR'), livePeriod($challenge));

        expect((string) $checkIn->expected_phrase)->toMatch('/\p{Arabic}/u');
    });

    it('falls back to the platform default when the participant has told us nothing', function () {
        $challenge = phraseChallenge();
        $checkIn = $this->issue->forParticipant(reader($challenge, null, null), livePeriod($challenge));

        expect((string) $checkIn->expected_phrase)->toMatch('/^[a-z]+ [a-z]+ [1-9][0-9]$/');
    });

    it('ignores a locale the platform does not serve', function () {
        $challenge = phraseChallenge();
        $checkIn = $this->issue->forParticipant(reader($challenge, null, 'de-DE'), livePeriod($challenge));

        expect((string) $checkIn->expected_phrase)->toMatch('/^[a-z]+ [a-z]+ [1-9][0-9]$/');
    });
});

describe('the vocabulary itself', function () {
    it('ships a bank for every locale the platform serves', function () {
        foreach (app(Localization::class)->codes() as $locale) {
            expect(lang_path("{$locale}/phrases.php"))->toBeFile();
        }
    });

    it('holds only words that are already in normalised form', function (string $locale) {
        $bank = require lang_path("{$locale}/phrases.php");

        foreach (['adjectives', 'nouns'] as $part) {
            foreach ($bank[$part] as $word) {
                // A word that folds to something else would be shown to the
                // participant in one form and compared in another.
                expect(CheckIn::normalisePhrase($word))->toBe($word);
            }
        }
    })->with(['en', 'fa']);

    it('is big enough that a busy period is not fighting for phrases', function (string $locale) {
        $bank = require lang_path("{$locale}/phrases.php");

        expect(count($bank['adjectives']))->toBeGreaterThanOrEqual(30)
            ->and(count($bank['nouns']))->toBeGreaterThanOrEqual(50)
            ->and(count(array_unique($bank['adjectives'])))->toBe(count($bank['adjectives']))
            ->and(count(array_unique($bank['nouns'])))->toBe(count($bank['nouns']));
    })->with(['en', 'fa']);

    it('puts the adjective after the noun in Farsi', function () {
        $bank = require lang_path('fa/phrases.php');

        expect($bank['template'])->toBe(':noun :adjective :number');
    });

    it('never reaches the client', function () {
        // The phrase is the answer to the challenge. Shipping the bank to the
        // browser would not leak anyone's phrase, but it advertises the shape of
        // every phrase to anyone reading the bundle for no benefit at all.
        expect(Config::array('localization.client_groups'))->not->toContain('phrases');

        foreach (array_keys(app(Localization::class)->clientCatalog('fa')) as $key) {
            expect($key)->not->toStartWith('phrases.');
        }
    });

    it('throws when a locale has no vocabulary at all', function () {
        vocabulary(['phrases.nothing' => 'here']);

        expect(fn () => $this->issue->generate('en'))->toThrow(PhraseUnavailableException::class);
    });

    it('throws when the template lost a placeholder', function () {
        vocabulary([
            'phrases.template' => ':adjective :number',
            'phrases.adjectives' => ['solo'],
            'phrases.nouns' => ['island'],
        ]);

        expect(fn () => $this->issue->generate('en'))
            ->toThrow(PhraseUnavailableException::class, 'no usable template');
    });
});
