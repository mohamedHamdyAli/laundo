<?php

namespace App\Support\Spreadsheet;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

/**
 * Validate one sheet row with the screen's own FormRequest.
 *
 * Not a copy of its rules: the request itself, built as if the form had posted
 * this row — POST for a new row, PUT with the route id for an edit — and run
 * through its full cycle: `prepareForValidation()`, `rules()`, `withValidator()`,
 * its messages and its attribute names. So a row is refused for exactly what the
 * form would refuse, in the same words, and a rule added to the form later
 * reaches the import with no second place to remember. `CouponRequest` dropping
 * a money field for somebody without `setting.update` is the kind of thing a
 * copied rule list would silently lose.
 */
class RowValidator
{
    /**
     * @param  class-string<FormRequest>  $requestClass
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the validated data
     *
     * @throws ValidationException
     */
    public function validate(string $requestClass, array $input, ?int $id, string $routeKey = 'id'): array
    {
        $method = $id === null ? 'POST' : 'PUT';
        $uri = $id === null ? '/spreadsheet-row' : '/spreadsheet-row/'.$id;

        /** @var FormRequest $request */
        $request = $requestClass::create($uri, $method, $input);
        $request->setContainer(app())->setRedirector(app(Redirector::class));
        $request->setUserResolver(fn () => auth()->user());

        $route = new Route($method, $id === null ? 'spreadsheet-row' : 'spreadsheet-row/{'.$routeKey.'}', []);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        // The form's own cycle: authorize, prepare, rules, after-hooks.
        $request->validateResolved();

        return $request->validated();
    }

    /**
     * The same request class without running it — for a sheet that needs to
     * know whether one exists.
     */
    public static function isFormRequest(?string $class): bool
    {
        return $class !== null && is_subclass_of($class, FormRequest::class);
    }
}
