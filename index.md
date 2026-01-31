---
layout: default
title: ホーム
---

{% include hero.html %}
{% include top-problems.html %}
{% include top-usp.html %}
{% include top-lineup.html %}
{% include top-price.html %}
{% include top-flow.html %}

<section id="top-works">
  <h2>施工事例</h2>
  <div class="works-grid">
    {% for work in site.works %}
      <article class="work-item">
        <a href="{{ work.url | relative_url }}">
          <img src="{{ work.thumbnail | default: '/assets/images/works/default-thumb.jpg' }}" alt="{{ work.title }} サムネイル">
          <h3>{{ work.title }}</h3>
        </a>
        {% if work.excerpt %}
          <p class="work-excerpt">{{ work.excerpt }}</p>
        {% endif %}
      </article>
    {% endfor %}
  </div>
</section>

<section id="contact">
  <h2>お問い合わせ</h2>
  <p><a href="/contact/">お問い合わせフォームはこちら</a></p>
</section>
