<?php
namespace App\Test\TestCase\Controller;

use App\Controller\BloodsController;
use Cake\TestSuite\IntegrationTestCase;

/**
 * App\Controller\BloodsController Test Case
 */
class BloodsControllerTest extends IntegrationTestCase
{

    /**
     * Fixtures
     *
     * @var array
     */
    public $fixtures = [
        'Bloods' => 'app.bloods',
        'MemberHistories' => 'app.member_histories',
        'Members' => 'app.members',
        'Parts' => 'app.parts',
        'Sexes' => 'app.sexes',
        'MemberTypes' => 'app.member_types',
        'Statuses' => 'app.statuses',
        'Histories' => 'app.histories'
    ];

    /**
     * Test index method
     *
     * @return void
     */
    public function testIndex()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test view method
     *
     * @return void
     */
    public function testView()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test add method
     *
     * @return void
     */
    public function testAdd()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test edit method
     *
     * @return void
     */
    public function testEdit()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test delete method
     *
     * @return void
     */
    public function testDelete()
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
