<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use XF\Style;

/**
 * globalTemplateData(), which hands a test the `xf` parameter a render would see.
 *
 * It is the only way to assert on what a templater_global_data listener contributed: the event
 * fires from inside XF\App::getGlobalTemplateData(), so the alternative is to have the listener
 * report on itself, which proves nothing about what reached the template.
 */
class GlobalTemplateDataTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_the_data_carries_a_style_where_xenforos_own_method_does_not()
	{
		// the direct call first, and the order is the whole point: once anything has installed the
		// style, XenForo's own method answers with it too, so a test written the other way round
		// would report no difference
		$direct = \XF::app()->getGlobalTemplateData(null);
		$this->assertNull(
			$direct['style'],
			'XF\App::getGlobalTemplateData() reads the style off the templater, which nothing has set'
		);

		$data = $this->globalTemplateData();

		$this->assertInstanceOf(Style::class, $data['style']);
	}

	public function test_a_listener_can_be_asserted_on_through_what_it_contributed()
	{
		$this->app()->extension()->addListener(
			'templater_global_data',
			function ($app, array &$data)
			{
				$data['zzProbeFromListener'] = 'contributed';
			}
		);

		$data = $this->globalTemplateData();

		$this->assertSame('contributed', $data['zzProbeFromListener'] ?? null);
	}

	public function test_without_the_listener_the_key_is_absent()
	{
		// the positive above would pass against any array carrying the key for another reason
		$this->assertArrayNotHasKey('zzProbeFromListener', $this->globalTemplateData());
	}

	public function test_a_reply_contributes_what_the_page_was()
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		$reply = $this->dispatch('tools', 'admin');

		$this->assertArrayNotHasKey(
			'reply',
			$this->globalTemplateData(),
			'nothing describes a reply until one is passed'
		);

		$data = $this->globalTemplateData($reply);

		// the short name as the route carries it, not the resolved class
		$this->assertSame('XF:Tools', $data['reply']['controller']);
		$this->assertSame('admin_section', $data['reply']['template']);
	}

	/**
	 * Which is the one way a renderTemplateWithoutModification() render differs from the
	 * renderReply() it is compared against: that helper renders through renderTemplate(), which
	 * passes no reply, so a template reading $xf.reply sees nothing on that side.
	 */
	public function test_only_a_render_given_the_reply_sees_what_page_it_is()
	{
		$this->fakesErrors();

		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		$reply = $this->dispatch('tools', 'admin');

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'admin',
			'title' => 'zzreplyprobe',
			'template' => 'TEMPLATE=[{$xf.reply.template}] CONTROLLER=[{$xf.reply.controller}]',
			'addon_id' => '',
		]);

		// the dispatched reply names its own template, so it is pointed at this one instead - the
		// reply's identity is what is being varied here, not the markup
		$reply->setTemplateName('zzreplyprobe');

		$this->assertSame(
			'TEMPLATE=[zzreplyprobe] CONTROLLER=[XF:Tools]',
			$this->renderReply($reply)
		);

		$this->assertSame(
			'TEMPLATE=[] CONTROLLER=[]',
			$this->renderTemplate('admin:zzreplyprobe'),
			'renderTemplate() passes no reply, so there is no $xf.reply to read'
		);
	}
}
