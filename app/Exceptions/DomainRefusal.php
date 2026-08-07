<?php

namespace App\Exceptions;

use App\Mcp\Concerns\TranslatesDomainRefusals;

/**
 * Marks an exception as a *domain refusal*: the write was rejected because
 * it would break one of the method's invariants, not because something
 * went wrong.
 *
 * The distinction matters at every boundary. A refusal is expected, it has
 * a message written for a human in PT-BR, and each surface knows how to
 * present it — a toast on the Kanban, a blocking modal in the Task Modal,
 * an MCP error with the offending state attached. A genuine failure is
 * none of those things and must keep bubbling.
 *
 * Implementing this interface is what lets callers catch "any refusal" in
 * one place ({@see TranslatesDomainRefusals}) instead of
 * enumerating exception classes tool by tool — the enumeration is exactly
 * what let `update-epic` silently miss the Emergência conflict.
 */
interface DomainRefusal {}
