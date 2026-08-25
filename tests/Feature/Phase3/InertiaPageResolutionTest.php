<?php

namespace Tests\Feature\Phase3;

use Tests\TestCase;

class InertiaPageResolutionTest extends TestCase
{
    public function test_all_phase_three_inertia_pages_resolve_with_exact_case(): void
    {
        $finder = app('inertia.view-finder');

        foreach ([
            'Collaboration/Index',
            'Collaboration/Workspace',
            'Collaboration/Announcements/Create',
            'Collaboration/Announcements/Edit',
        ] as $component) {
            $this->assertFileExists($finder->find($component), $component);
        }
    }
}
