<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;

/**
 * A test that asserts a template modification put something on the page needs the other half:
 * the same render without it. These read what the forum has, so the happy path skips on an
 * install with no applying modification rather than passing vacuously.
 */
class RenderWithoutModificationTest extends TestCase
{
	// the template and modification one test creates are real rows, so they are rolled back rather
	// than left on the forum - without this the class leaks a pair per run
	use UsesDatabaseTransactions;

	/**
	 * An enabled modification that is actually applying, with the template it modifies.
	 *
	 * @return array|null - [key, type, template]
	 */
	private function applyingModification()
	{
		$row = $this->app()->db()->fetchRow(
			'SELECT m.modification_key, m.type, m.template
				FROM xf_template_modification AS m
				INNER JOIN xf_template_modification_log AS l
					ON (l.modification_id = m.modification_id AND l.apply_count > 0)
				INNER JOIN xf_template AS t
					ON (t.type = m.type AND t.title = m.template AND t.style_id = 0)
				WHERE m.enabled = 1 AND m.type = \'public\'
				ORDER BY m.modification_key
				LIMIT 1'
		);

		return $row ?: null;
	}

	/**
	 * The template and the modification are this test's own, because a forum's own modification is
	 * not a subject this can assert on: whether leaving one out changes a standalone render depends
	 * on which modification the install happens to have, and on a forum whose first applying
	 * modification edits a branch that needs page parameters the two renders are identical. The
	 * test then fails with nothing wrong. Measured on a fresh install, where it did.
	 */
	public function test_leaving_a_modification_out_changes_the_render()
	{
		$this->fakesErrors();

		$title = 'zzwithoutmod_' . substr(md5(uniqid('', true)), 0, 6);
		$key = 'zzwithoutmod_' . substr(md5(uniqid('', true)), 0, 6);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => $title,
			'template' => '<div>ORIGINAL_MARKER</div>',
			'addon_id' => '',
		]);

		$this->createEntity('XF:TemplateModification', [
			'type' => 'public',
			'template' => $title,
			'modification_key' => $key,
			'description' => 'leaves a marker this test can see',
			'execution_order' => 10,
			'enabled' => true,
			'action' => 'str_replace',
			'find' => 'ORIGINAL_MARKER',
			'replace' => 'MODIFIED_MARKER',
			'addon_id' => '',
		]);

		$with = $this->renderTemplate('public:' . $title);
		$without = $this->renderTemplateWithoutModification($key);

		$this->assertNotSame($with, $without, "leaving $key out of public:$title changed nothing");

		// the pair DOCS recommends, and the positive half is what says the modification applied
		// at all rather than the comparison passing because one render failed
		$this->assertSee($with, 'MODIFIED_MARKER', false);
		$this->assertDontSee($with, 'ORIGINAL_MARKER', false);
		$this->assertSee($without, 'ORIGINAL_MARKER', false);
		$this->assertDontSee($without, 'MODIFIED_MARKER', false);
	}

	/**
	 * A phrase renders as its text on both sides, so a with/without comparison can be made on
	 * phrase text. The modification leaves the phrase alone, which is what makes the text on the
	 * modification-free side the compile's work rather than the modification's.
	 */
	public function test_a_phrase_renders_as_its_text_without_the_modification_too()
	{
		$this->fakesErrors();

		$title = 'zzwithoutmod_' . substr(md5(uniqid('', true)), 0, 6);
		$key = 'zzwithoutmod_' . substr(md5(uniqid('', true)), 0, 6);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => $title,
			'template' => '<h3>{{ phrase(\'followers\') }}</h3><div>ORIGINAL_MARKER</div>',
			'addon_id' => '',
		]);

		$this->createEntity('XF:TemplateModification', [
			'type' => 'public',
			'template' => $title,
			'modification_key' => $key,
			'description' => 'leaves the phrase alone',
			'execution_order' => 10,
			'enabled' => true,
			'action' => 'str_replace',
			'find' => 'ORIGINAL_MARKER',
			'replace' => 'MODIFIED_MARKER',
			'addon_id' => '',
		]);

		$with = $this->renderTemplate('public:' . $title);
		$without = $this->renderTemplateWithoutModification($key);

		$this->assertSee($with, '<h3>Followers</h3>', false);
		$this->assertSee($without, '<h3>Followers</h3>', false);
		$this->assertDontSee($without, '<h3>followers</h3>', false);
	}

	/**
	 * Handed the reply, the render without the modification sees the same $xf.reply as the
	 * renderReply() it is compared against - so the two differ by the modification alone. Without
	 * it, a template reading $xf.reply differs on both counts.
	 */
	public function test_given_the_reply_the_render_sees_the_same_page_as_render_reply()
	{
		$this->fakesErrors();

		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		$reply = $this->dispatch('tools', 'admin');

		$title = 'zzreplymod_' . substr(md5(uniqid('', true)), 0, 6);
		$key = 'zzreplymod_' . substr(md5(uniqid('', true)), 0, 6);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'admin',
			'title' => $title,
			'template' => 'CONTROLLER=[{$xf.reply.controller}] ORIGINAL_MARKER',
			'addon_id' => '',
		]);

		$this->createEntity('XF:TemplateModification', [
			'type' => 'admin',
			'template' => $title,
			'modification_key' => $key,
			'description' => 'leaves a marker this test can see',
			'execution_order' => 10,
			'enabled' => true,
			'action' => 'str_replace',
			'find' => 'ORIGINAL_MARKER',
			'replace' => 'MODIFIED_MARKER',
			'addon_id' => '',
		]);

		$reply->setTemplateName($title);

		$this->assertSame('CONTROLLER=[XF:Tools] MODIFIED_MARKER', $this->renderReply($reply));

		$this->assertSame(
			'CONTROLLER=[XF:Tools] ORIGINAL_MARKER',
			$this->renderTemplateWithoutModification($key, $reply->getParams(), $reply),
			'given the reply, only the modification differs'
		);

		$this->assertSame(
			'CONTROLLER=[] ORIGINAL_MARKER',
			$this->renderTemplateWithoutModification($key, $reply->getParams()),
			'without the reply there is no $xf.reply to read, as before'
		);
	}


	/**
	 * The injected template is removed afterwards, so the next render of the same template in the
	 * same test is the forum's own again. Without that the helper would quietly replace the
	 * template for the rest of the test.
	 */
	public function test_the_real_template_renders_again_afterwards()
	{
		$modification = $this->applyingModification();

		if (!$modification)
		{
			$this->markTestSkipped('This forum has no applying public template modification.');
		}

		$this->fakesErrors();

		$name = $modification['type'] . ':' . $modification['template'];

		$before = $this->renderTemplate($name);
		$this->renderTemplateWithoutModification($modification['modification_key']);
		$after = $this->renderTemplate($name);

		$this->assertSame($before, $after, 'the modification-free template was left in the cache');
	}

	/**
	 * The compiled template goes through a temporary file, and tempnam() creates the file it names
	 * - so writing to a path with an extension appended leaves the created one behind on every
	 * call. This counts them.
	 */
	public function test_no_temporary_file_is_left_behind()
	{
		$modification = $this->applyingModification();

		if (!$modification)
		{
			$this->markTestSkipped('This forum has no applying public template modification.');
		}

		$this->fakesErrors();

		$pattern = sys_get_temp_dir() . '/xftpl*';
		$before = count((array) glob($pattern));

		$this->renderTemplateWithoutModification($modification['modification_key']);

		$this->assertCount(
			$before,
			(array) glob($pattern),
			'a temporary file was left in ' . sys_get_temp_dir()
		);
	}

	public function test_an_unknown_modification_key_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('No template modification with the key \'zzNotAModification\'');

		$this->renderTemplateWithoutModification('zzNotAModification');
	}

	public function test_a_disabled_modification_is_refused()
	{
		$key = $this->app()->db()->fetchOne(
			'SELECT modification_key FROM xf_template_modification WHERE enabled = 0 LIMIT 1'
		);

		if (!$key)
		{
			$this->markTestSkipped('This forum has no disabled template modification.');
		}

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is disabled');

		$this->renderTemplateWithoutModification($key);
	}
}
