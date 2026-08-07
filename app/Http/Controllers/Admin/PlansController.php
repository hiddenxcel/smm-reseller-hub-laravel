<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceKey;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Admin\PlanActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The price list.
 *
 * Small enough that the whole thing is one page with no pagination — there is
 * one plan per service, and there are five services.
 */
class PlansController extends Controller
{
    public function index(): Response
    {
        $this->authorise('billing.view');

        $plans = Plan::orderBy('sort_order')->orderBy('id')->get();
        $live = PlanActions::liveCountsFor($plans->pluck('id')->all());

        return Inertia::render('Admin/Plans/Index', [
            'plans' => $plans
                ->map(fn (Plan $plan) => [
                    ...PlanActions::toRow($plan, $live[$plan->id] ?? 0),
                    'terms' => PlanActions::termPreview($plan),
                ])
                ->all(),
            'serviceKeys' => array_map(
                fn ($case) => $case->value,
                ServiceKey::cases(),
            ),
            'canManage' => Auth::guard('superadmin')->user()->can('billing.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorise('billing.manage');

        $validated = $request->validate($this->rules());

        PlanActions::create($validated);

        return back()->with('success', 'Plan created.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $this->authorise('billing.manage');

        $validated = $request->validate($this->rules($plan));

        PlanActions::for($plan)->update($validated);

        return back()->with(
            'success',
            'Plan updated. Resellers keep their current price until they renew.',
        );
    }

    /**
     * Retire or restore. There is no delete: the payments and subscriptions
     * that reference a plan are the record of what people paid, and removing
     * the row they point at would orphan that history.
     */
    public function act(Plan $plan, string $action): RedirectResponse
    {
        $this->authorise('billing.manage');

        $actions = PlanActions::for($plan);

        if ($action === 'retire') {
            $actions->retire();

            return back()->with('success', "{$plan->name} taken off sale. Existing subscriptions are unaffected.");
        }

        $actions->restore();

        return back()->with('success', "{$plan->name} is on sale again.");
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Plan $plan = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('plans', 'code')->ignore($plan?->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'service_key' => [
                'required', 'string',
                Rule::in(array_map(fn ($case) => $case->value, ServiceKey::cases())),
            ],
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'price_yearly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['required', 'string', 'size:3'],
            'max_panels' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_numbers' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_orders_monthly' => ['required', 'integer', 'min:0', 'max:10000000'],
            'max_messages_monthly' => ['required', 'integer', 'min:0', 'max:10000000'],
            'max_refills_monthly' => ['required', 'integer', 'min:0', 'max:10000000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }

    private function authorise(string $ability): void
    {
        if (! Auth::guard('superadmin')->user()->can($ability)) {
            throw new AccessDeniedHttpException("Your role cannot {$ability}.");
        }
    }
}
