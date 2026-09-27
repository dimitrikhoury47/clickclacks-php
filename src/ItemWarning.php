<?php

declare(strict_types=1);

namespace ClickClacks;

/**
 * A per-item warning from the API: the item was accepted, with a change, such as a
 * dropped group trait (`group_trait_dropped`). Warnings are not errors; nothing is retried.
 */
final class ItemWarning
{
    public function __construct(
        /** The item's index within the request that was sent, or -1 when the API gave none. */
        public readonly int $index,
        /** For example `group_trait_dropped`. */
        public readonly string $code,
        /** For example `timestamp` or `properties.email`. */
        public readonly ?string $field = null,
        public readonly ?string $message = null,
        /** The item's `insert_id`, to match it to your own records. */
        public readonly ?string $insertId = null,
        /** The item's event name: `$identify` for identify items, `$group_identify` for group items. */
        public readonly ?string $event = null,
    ) {}

    /**
     * @return array{index: int, code: string, field: ?string, message: ?string, insert_id: ?string, event: ?string}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'code' => $this->code,
            'field' => $this->field,
            'message' => $this->message,
            'insert_id' => $this->insertId,
            'event' => $this->event,
        ];
    }
}
