<?php

namespace App\Observers;

use App\Models\Document;
use App\Support\RealtimeEntitySync;

class DocumentRealtimeObserver
{
    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document): void
    {
        $this->touch();
    }

    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        $this->touch();
    }

    /**
     * Handle the Document "deleted" event.
     */
    public function deleted(Document $document): void
    {
        $this->touch();
    }

    /**
     * Handle the Document "restored" event.
     */
    public function restored(Document $document): void
    {
        $this->touch();
    }

    /**
     * Handle the Document "force deleted" event.
     */
    public function forceDeleted(Document $document): void
    {
        $this->touch();
    }

    private function touch(): void
    {
        RealtimeEntitySync::touch(RealtimeEntitySync::ENTITY_DOCUMENT);
    }
}
