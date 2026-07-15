<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\DispatchTripController;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class DispatchTripIdResolutionTest extends TestCase
{
    public function test_resolve_trip_id_rejects_non_numeric_values(): void
    {
        $controller = $this->app->make(DispatchTripController::class);
        $resolve = new ReflectionMethod($controller, 'resolveTripId');
        $resolve->setAccessible(true);

        foreach (['undefined', 'null', '', 'abc', '0', '-1'] as $invalid) {
            try {
                $resolve->invoke($controller, $invalid);
                $this->fail("Expected 404 for trip id [{$invalid}]");
            } catch (NotFoundHttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }

        $this->assertSame(63, $resolve->invoke($controller, '63'));
        $this->assertSame(63, $resolve->invoke($controller, 63));
    }
}
