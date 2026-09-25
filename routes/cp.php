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

    Route::post('conversations/{inboxConversation}/contact', [ConversationsController::class, 'createContact'])
        ->whereNumber('inboxConversation')->middleware('can:reply inbox')->name('conversations.contact');

    Route::post('conversations/{inboxConversation}/accept', [ConversationsController::class, 'accept'])
        ->whereNumber('inboxConversation')->middleware('can:reply inbox')->name('conversations.accept');

    Route::post('conversations/{inboxConversation}/block', [ConversationsController::class, 'block'])
        ->whereNumber('inboxConversation')->middleware('can:reply inbox')->name('conversations.block');

    Route::get('attachments/{inboxAttachment}', [AttachmentsController::class, 'show'])
        ->whereNumber('inboxAttachment')->middleware('can:view inbox')->name('attachments.show');

    Route::middleware('can:manage inbox mailboxes')->group(function () {
        Route::get('mailboxes', [MailboxesController::class, 'index'])->name('mailboxes.index');
        Route::get('mailboxes/create', [MailboxesController::class, 'create'])->name('mailboxes.create');
        Route::post('mailboxes', [MailboxesController::class, 'store'])->name('mailboxes.store');
        Route::post('mailboxes/test', [MailboxesController::class, 'testNew'])->name('mailboxes.test-new');
        Route::get('mailboxes/{inboxMailbox}/edit', [MailboxesController::class, 'edit'])
            ->whereNumber('inboxMailbox')->name('mailboxes.edit');
        Route::patch('mailboxes/{inboxMailbox}', [MailboxesController::class, 'update'])
            ->whereNumber('inboxMailbox')->name('mailboxes.update');
        Route::post('mailboxes/{inboxMailbox}/test', [MailboxesController::class, 'test'])
            ->whereNumber('inboxMailbox')->name('mailboxes.test');
        Route::delete('mailboxes/{inboxMailbox}/rules/{inboxRule}', [MailboxesController::class, 'destroyRule'])
            ->whereNumber(['inboxMailbox', 'inboxRule'])->name('mailboxes.rules.destroy');
    });
});
