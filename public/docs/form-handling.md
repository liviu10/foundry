# Form handling

> A complete form workflow with Foundry - reading the request, CSRF protection, validation, saving, flash messages and the redirect.

Canonical: https://foundry.insightdevhub.ro/docs/form-handling/

This guide puts together `Request`, `Csrf`, `Validator`, `RoValidator`, `Session`, `Flash` and `Response` in the classic POST / redirect / GET flow.

## The controller

```php
use Foundry\Csrf;
use Foundry\Flash;
use Foundry\Request;
use Foundry\Response;
use Foundry\RoValidator;
use Foundry\Session;
use Foundry\Validator;

$request = Request::fromGlobals();

if ($request->isPost()) {
    Csrf::check();                                    // invalid token -> exception (HTTP status 419)

    $validator = new Validator($request->all(), [
        'email' => 'required|email|max:255',
        'cnp' => ['required', 'cnp' => RoValidator::cnp(...)],
        'birth_date' => 'required|date:d.m.Y',
    ]);

    if ($validator->fails()) {
        Session::flashInput($request->only('email', 'birth_date'));
        Session::flash('errors', $validator->errors());
        Response::redirect('/clients/new')->send();

        return;
    }

    $db->insert('clients', $validator->validated());
    Flash::success('Client was saved successfully.');
    Response::redirect('/clients')->send();

    return;
}
```

## Step by step

1. `Request::fromGlobals()` wraps the superglobals with typed getters. `$request->int('id')` returns an integer or throws: a missing value, an array (`?id[]=1`) or `'12abc'` never slips through.
2. `Csrf::check()` verifies the session-bound token for POST, PUT, PATCH and DELETE requests and throws when it is missing or wrong.
3. `Validator` applies rules per field. Invalid data does **not** throw: each field gets one error, with a stable code (`codes()`) and a message (`errors()`).
4. Custom rules are closures. `RoValidator` methods return booleans, so they plug in directly as `'cnp' => RoValidator::cnp(...)`.
5. On failure, the input and the errors are flashed to the session and the user is redirected back to the form.
6. On success, `validated()` returns only the validated fields, ready for `insert()`. A flash message confirms the result after the redirect.

> **Warning:** `Response::send()` does not stop the script. The `return` after each redirect is required.

## The template

The UI helpers return data, not HTML, so the markup stays yours. Use `e()` (or `View::escape()`) for every value you print:

```php
<?php use function Foundry\e; ?>
<?php foreach (Flash::pull() as $message): ?>
    <div class="alert alert-<?= e($message['type']) ?>"><?= e($message['message']) ?></div>
<?php endforeach ?>

<form method="post" action="/clients/new">
    <?= Csrf::field() ?>

    <label for="email">Email</label>
    <input id="email" name="email" value="<?= e(Session::old('email')) ?>">
    <?php if (isset(Session::flashed('errors', [])['email'])): ?>
        <p class="error"><?= e(Session::flashed('errors')['email']) ?></p>
    <?php endif ?>

    <button type="submit">Save</button>
</form>
```

## Public forms

For forms anyone can submit (contact, registration, password reset), add `BotDetection` (a honeypot field plus a signed minimum fill time) and `RateLimiter` (for example 5 attempts per minute per IP address).
