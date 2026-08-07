<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posts on the public blog.
 *
 * Platform-owned rather than tenant-owned: this is our writing about the
 * product, not something a reseller publishes, so there is no tenant_id and
 * no scoping to get wrong.
 *
 * A post is only public once `published_at` is set and in the past. Storing
 * the moment rather than a boolean is what lets a post be written today and
 * appear on Monday without anyone remembering to press a button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();

            // The URL. Unique because it is the address, and immutable in
            // practice — changing it breaks every link already shared.
            $table->string('slug')->unique();

            $table->string('title');

            // Shown on the index and in link previews. Kept separate from the
            // body so the summary is written deliberately rather than cut
            // from the first paragraph.
            $table->string('excerpt', 300);

            // Markdown, rendered at read time.
            $table->text('body');

            $table->string('author')->nullable();

            // Null means draft. Future means scheduled.
            $table->timestamp('published_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
