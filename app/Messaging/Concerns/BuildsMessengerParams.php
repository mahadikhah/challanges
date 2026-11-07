<?php

namespace App\Messaging\Concerns;

/**
 * The parameters every platform's sends have in common.
 *
 * Both transports speak the same two wire conventions — a caption is one
 * blank-line-separated string, and `reply_markup` is a JSON-serialized object
 * rather than a form-encoded nested array — so the normalisation lives here
 * once instead of inline in each method of each platform. Nothing platform
 * specific belongs in this trait: the media field name, the endpoint and the
 * SDK call stay in the implementation.
 */
trait BuildsMessengerParams
{
    /**
     * Media params with the caption filled in and the keyboard added when one
     * is given.
     *
     * The caption is always present, even when empty, so a caller that passes
     * no lines sends the same message it always did. The keyboard key is
     * omitted rather than nulled — the wire reads a null `reply_markup` as an
     * empty object, which would replace whatever keyboard is on screen.
     *
     * @param  array<string, mixed>  $params  the media field already in place
     * @param  list<string|null>  $captionLines
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard
     * @return array<string, mixed>
     */
    private function mediaParams(array $params, array $captionLines, ?array $inlineKeyboard): array
    {
        $params['caption'] = $this->captionFrom($captionLines);

        $markup = $this->replyMarkupJson($inlineKeyboard);

        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }

        return $params;
    }

    /**
     * A caption, laid out the way `BotMessenger::paragraphs` lays out a message:
     * blank-line separated, with nulls and blanks dropped.
     *
     * @param  list<string|null>  $captionLines
     */
    private function captionFrom(array $captionLines): string
    {
        return implode("\n\n", array_filter(
            $captionLines,
            static fn (?string $line): bool => $line !== null && trim($line) !== '',
        ));
    }

    /**
     * The `reply_markup` value for a keyboard, or null when there is none.
     *
     * The SDK passes params straight through as form fields and does not
     * serialize this one, and the platform documents `reply_markup` as a
     * JSON-serialized object — form-encoding the nested array would be
     * rejected.
     *
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard
     */
    private function replyMarkupJson(?array $inlineKeyboard): ?string
    {
        if ($inlineKeyboard === null) {
            return null;
        }

        return json_encode(
            ['inline_keyboard' => $inlineKeyboard],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }
}
