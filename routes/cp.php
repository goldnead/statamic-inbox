<?php

use Goldnead\StatamicInbox\Http\Controllers\Cp\AttachmentsController;
use Goldnead\StatamicInbox\Http\Controllers\Cp\ConversationsController;
use Goldnead\StatamicInbox\Http\Controllers\Cp\MailboxesController;
use Illuminate\Support\Facades\Route;

// Parameters are named inboxConversation, inboxMailbox, inboxAttachment and
// resolved in the controllers, not bound: a Route::bind() a sibling registers
// for a generic name applies to every route in the application.

Route::prefix('inbox')->name('inbox.')->group(function () {
    Route::get('/', [ConversationsController::class, 'index'])
        ->middleware('can:view inbox')->name('index');

    Route::get('conversations/{inboxConversation}', [ConversationsController::class, 'show'])
        ->whereNumber('inboxConversation')->middleware('can:view inbox')->name('conversations.show');

    Route::patch('conversations/{inboxConversation}', [ConversationsController::class, 'update'])
        ->whereNumber('inboxConversation')->middleware('can:reply inbox')->name('conversations.update');

    Route::post('conversations/{inboxConversation}/reply', [ConversationsController::class, 'reply'])
        ->whereNumber('inboxConversation')->middleware('can:reply inbox')->name('conversations.reply');

    Route::post('conversations/{inboxConversation}/draft', [ConversationsController::class, 'draft'])
        ->whereNumber('inboxConversation')->middleware('can:reply inbox')->name('conversations.draft');

    Route::post('conversations/{inboxConversation}/template', [ConversationsController::class, 'template'])
        ->whereNumber('inboxConversation')->middleware('can:reply inbox')->name('conversations.template');

    Route::get('attachments/{inboxAttachment}', [AttachmentsController::class, 'show'])
        ->whereNumber('inboxAttachment')->middleware('can:view inbox')->name('attachments.show');

    Route::middleware('can:manage inbox mailboxes')->group(function () {
        Route::get('mailboxes', [MailboxesController::class, 'index'])->name('mailboxes.index');
        Route::post('mailboxes', [MailboxesController::class, 'store'])->name('mailboxes.store');
        Route::get('mailboxes/{inboxMailbox}/edit', [MailboxesController::class, 'edit'])
            ->whereNumber('inboxMailbox')->name('mailboxes.edit');
        Route::patch('mailboxes/{inboxMailbox}', [MailboxesController::class, 'update'])
            ->whereNumber('inboxMailbox')->name('mailboxes.update');
        Route::post('mailboxes/{inboxMailbox}/test', [MailboxesController::class, 'test'])
            ->whereNumber('inboxMailbox')->name('mailboxes.test');
    });
});
