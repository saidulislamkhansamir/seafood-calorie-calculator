/**
 * Seafood Calorie Calculator — script.js
 * The Khan Digital · v2.1.0
 *
 * Handles:
 *  - Tab switching
 *  - Live food search with debounce
 *  - AJAX calorie calculation with omega-3, mercury & health tip
 *  - Meal tracker (add / remove / totals)
 */

(function ($) {
  'use strict';

  /* ══════════════════════════════════════════════════════════════
     STATE
  ══════════════════════════════════════════════════════════════ */
  let selectedFood = null;
  let lastResult   = null;
  let mealItems    = [];
  let searchTimer  = null;
  let activeFilter = null;

  /* ══════════════════════════════════════════════════════════════
     TABS
  ══════════════════════════════════════════════════════════════ */
  $(document).on('click', '.fcc-tab', function () {
    const target = $(this).data('tab');
    $('.fcc-tab').removeClass('active');
    $(this).addClass('active');
    $('.fcc-tab-content').removeClass('active');
    $('#tab-' + target).addClass('active');
  });

  /* ══════════════════════════════════════════════════════════════
     HEALTH GOAL FILTERS — client-side
  ══════════════════════════════════════════════════════════════ */
  $(document).on('click', '.fcc-filter-btn', function () {
    $('.fcc-filter-btn').removeClass('active');
    $(this).addClass('active');
    activeFilter = $(this).data('filter') === 'all' ? null : $(this).data('filter');
    if (!activeFilter) {
      closeSuggestions();
      return;
    }
    $('#fcc-food-search').val('').trigger('focus');
    applyFilter('');
  });

  function applyFilter(query) {
    var foods = fcc_ajax.foods || [];
    var q = (query || '').toLowerCase();
    var sorted;

    if (activeFilter === 'omega3') {
      sorted = foods.slice().sort(function (a, b) { return b.omega3 - a.omega3; });
    } else if (activeFilter === 'low_cal') {
      sorted = foods.slice().sort(function (a, b) { return a.calories - b.calories; });
    } else if (activeFilter === 'protein') {
      sorted = foods.slice().sort(function (a, b) { return b.protein - a.protein; });
    } else if (activeFilter === 'low_mercury') {
      var mercOrder = { low: 0, moderate: 1, high: 2 };
      sorted = foods.slice().sort(function (a, b) {
        return (mercOrder[a.mercury] || 0) - (mercOrder[b.mercury] || 0);
      });
    } else if (activeFilter === 'eco') {
      sorted = foods.filter(function (f) {
        return fcc_ajax.eco && fcc_ajax.eco[f.id] && fcc_ajax.eco[f.id].r === 'good';
      });
    } else {
      sorted = foods.slice();
    }

    if (q) {
      sorted = sorted.filter(function (f) {
        return f.name.toLowerCase().indexOf(q) !== -1 ||
               f.category.toLowerCase().indexOf(q) !== -1;
      });
    }

    var limit   = (fcc_ajax.settings && fcc_ajax.settings.search_results_limit) || 8;
    var results = sorted.slice(0, limit);

    if (!results.length) {
      $('#fcc-food-suggestions')
        .html('<div class="fcc-suggestion-item"><span class="s-name" style="color:#64748b">No results</span></div>')
        .addClass('open');
      return;
    }
    renderSuggestions(results);
  }

  /* ══════════════════════════════════════════════════════════════
     FOOD SEARCH — debounced AJAX (or client-side when filter active)
  ══════════════════════════════════════════════════════════════ */
  $(document).on('input', '#fcc-food-search', function () {
    const q = $(this).val().trim();
    clearTimeout(searchTimer);

    // Don't disturb the dropdown while the request form is open
    if ($('#fcc-food-suggestions .fcc-request-form:visible').length) return;

    if (activeFilter) {
      applyFilter(q);
      return;
    }

    var minChars = (fcc_ajax.settings && fcc_ajax.settings.search_min_chars) || 1;
    if (q.length < minChars) {
      closeSuggestions();
      return;
    }

    searchTimer = setTimeout(function () {
      // Guard again in case form opened during the 250ms debounce
      if ($('#fcc-food-suggestions .fcc-request-form:visible').length) return;
      $.ajax({
        url: fcc_ajax.ajax_url,
        method: 'POST',
        data: {
          action: 'fcc_search_food',
          nonce: fcc_ajax.nonce,
          query: q,
        },
        success: function (res) {
          // Guard again: AJAX is async — form may have opened while request was in flight
          if ($('#fcc-food-suggestions .fcc-request-form:visible').length) return;
          var limit = (fcc_ajax.settings && fcc_ajax.settings.search_results_limit) || 8;
          if (res.success && res.data.length) {
            renderSuggestions(res.data.slice(0, limit));
          } else {
            showNoResults(q);
          }
        },
      });
    }, 250);
  });

  $(document).on('focus', '#fcc-food-search', function () {
    if (activeFilter && !$(this).val().trim()) {
      applyFilter('');
    }
  });

  function renderSuggestions(foods) {
    const $drop = $('#fcc-food-suggestions').empty().addClass('open');
    foods.forEach(function (food) {
      const $item = $('<div class="fcc-suggestion-item">')
        .html(
          '<div>' +
            '<div class="s-name">' + escHtml(food.name) + '</div>' +
            '<div class="s-meta">' + escHtml(food.category) + ' &middot; ' + food.calories + ' kcal / 100g</div>' +
          '</div>' +
          '<span class="s-badge">' + food.calories + ' kcal</span>'
        )
        .data('food', food);
      $drop.append($item);
    });
  }

  $(document).on('click', '.fcc-suggestion-item', function () {
    const food = $(this).data('food');
    if (!food) return;
    selectedFood = food;
    $('#fcc-food-search').val(food.name);
    closeSuggestions();
    $('#fcc-selected-name').text(food.name);
    $('#fcc-serving-section').slideDown(200);
    $('#fcc-results').hide();
    trackSearchEvent(food.id, food.name);
  });

  $(document).on('click', function (e) {
    // Also check #fcc-food-suggestions directly — some themes move absolute-positioned
    // dropdowns out of their parent to <body>, breaking the .fcc-search-wrap ancestor check.
    if (!$(e.target).closest('.fcc-search-wrap, #fcc-food-suggestions').length) {
      closeSuggestions();
    }
  });

  function closeSuggestions() {
    $('#fcc-food-suggestions').removeClass('open').empty();
  }

  function showNoResults(query) {
    var safe = escHtml(query);
    var cfg  = fcc_ajax.settings || {};
    var reqHtml = cfg.show_request_btn !== 0 ? (
        '<button class="fcc-request-toggle" type="button"><span class="fcc-req-btn-long">Missing? Suggest adding this</span><span class="fcc-req-btn-short">Suggest adding</span></button>' +
        '<div class="fcc-request-form" style="display:none">' +
          '<p class="fcc-request-hint">Let us know — we\'ll consider adding it to the calculator.</p>' +
          '<input class="fcc-request-name" type="text" value="' + safe + '" maxlength="150" placeholder="Food name">' +
          '<textarea class="fcc-request-note" maxlength="300" placeholder="Any notes? e.g. where you buy it (optional)"></textarea>' +
          '<div class="fcc-request-actions">' +
            '<button class="fcc-request-submit" type="button">Send Request</button>' +
            '<span class="fcc-request-sent" style="display:none"><i class="fas fa-check-circle"></i> Sent! Thank you.</span>' +
          '</div>' +
        '</div>'
    ) : '';
    var html =
      '<div class="fcc-no-results-wrap">' +
        '<div class="fcc-no-results-msg">' +
          '<i class="fas fa-fish" style="opacity:.35;margin-right:6px"></i>' +
          'No results for <strong>' + safe + '</strong>' +
        '</div>' +
        reqHtml +
      '</div>';
    $('#fcc-food-suggestions').html(html).addClass('open');

    if (query.length >= 3 && cfg.auto_log_searches !== 0) {
      $.post(fcc_ajax.ajax_url, {
        action: 'fcc_log_missing_search',
        nonce:  fcc_ajax.nonce,
        query:  query
      });
    }
  }

  // Toggle request form
  $(document).on('click', '.fcc-request-toggle', function (e) {
    e.stopPropagation(); // prevent theme document-click handlers from closing the dropdown
    var $form = $(this).siblings('.fcc-request-form');
    if ($form.is(':visible')) {
      $form.slideUp(180);
      $(this).html('<span class="fcc-req-btn-long">Missing? Suggest adding this</span><span class="fcc-req-btn-short">Suggest adding</span>');
    } else {
      $form.slideDown(200);
      $(this).html('<span class="fcc-req-btn-long">✕ Cancel request</span><span class="fcc-req-btn-short">✕ Cancel</span>');
      $form.find('.fcc-request-name').focus();
    }
  });

  // Submit food request
  $(document).on('click', '.fcc-request-submit', function (e) {
    e.stopPropagation();
    var $btn      = $(this);
    var $wrap     = $btn.closest('.fcc-request-form');
    var food_name = $wrap.find('.fcc-request-name').val().trim();
    var note      = $wrap.find('.fcc-request-note').val().trim();
    if (!food_name) { $wrap.find('.fcc-request-name').focus(); return; }
    $btn.prop('disabled', true).text('Sending…');
    $.post(fcc_ajax.ajax_url, {
      action:    'fcc_submit_food_request',
      nonce:     fcc_ajax.nonce,
      food_name: food_name,
      note:      note
    }, function (res) {
      if (res.success) {
        $btn.hide();
        $wrap.find('.fcc-request-sent').fadeIn(200);
        $wrap.find('.fcc-request-note').prop('disabled', true);
        $wrap.find('.fcc-request-name').prop('disabled', true);
      } else {
        $btn.prop('disabled', false).text('Send Request');
      }
    });
  });

  function trackSearchEvent(foodId, foodName) {
    if (!foodId) return;
    $.post(fcc_ajax.ajax_url, {
      action:    'fcc_track_event',
      nonce:     fcc_ajax.nonce,
      food_id:   foodId,
      food_name: foodName,
      event:     'search',
    });
  }

  $(document).on('click', '#fcc-clear-food', function () {
    selectedFood = null;
    lastResult   = null;
    activeFilter = null;
    $('.fcc-filter-btn').removeClass('active');
    $('.fcc-filter-btn[data-filter="all"]').addClass('active');
    $('#fcc-food-search').val('');
    $('#fcc-serving-section').slideUp(200);
    $('#fcc-results').slideUp(200);
    closeSuggestions();
  });

  /* ══════════════════════════════════════════════════════════════
     CALCULATE CALORIES — AJAX
  ══════════════════════════════════════════════════════════════ */
  $(document).on('click', '#fcc-calculate-btn', function () {
    if (!selectedFood) {
      pulse('#fcc-food-search', 'Please select a seafood item first.');
      return;
    }

    const $btn    = $(this);
    const serving = parseFloat($('#fcc-serving-size').val()) || 100;
    const unit    = $('#fcc-serving-unit').val();
    const method  = $('#fcc-method').val() || 'baked';

    setLoading($btn, true);

    $.ajax({
      url: fcc_ajax.ajax_url,
      method: 'POST',
      data: {
        action:  'fcc_calculate_calories',
        nonce:   fcc_ajax.nonce,
        food_id: selectedFood.id,
        serving: serving,
        unit:    unit,
        method:  method,
      },
      success: function (res) {
        setLoading($btn, false);
        if (res.success) {
          lastResult = res.data;
          renderCalorieResults(res.data);
        } else {
          alert('Error: ' + (res.data || 'Unknown error'));
        }
      },
      error: function () {
        setLoading($btn, false);
        alert('AJAX error. Please try again.');
      },
    });
  });

  function renderCalorieResults(d) {
    // Hero
    animateNumber('#res-calories', 0, d.total_cal, 800);
    var methodKey   = $('#fcc-method').val() || 'baked';
    var methodObj   = (fcc_ajax.methods || []).find(function (m) { return m.key === methodKey; });
    var methodLabel = methodObj ? methodObj.label : 'Baked';
    $('#res-food-name').text(d.food_name);
    $('#res-serving-info').text(d.serving_g + 'g · ' + methodLabel);
    $('#res-category-badge').text(d.category);

    // Macro boxes
    $('#res-protein').text(d.protein_g + 'g');
    $('#res-carbs').text(d.carbs_g + 'g');
    $('#res-fat').text(d.fat_g + 'g');
    $('#res-fiber').text(d.fiber_g + 'g');
    $('#res-protein-cals').text(d.cal_protein + ' kcal');
    $('#res-carbs-cals').text(d.cal_carbs + ' kcal');
    $('#res-fat-cals').text(d.cal_fat + ' kcal');
    $('#res-sugar-val').text('Sugar: ' + d.sugar_g + 'g');

    // Macro bars — omega3Pct computed first so the closure captures the right value
    var omega3Target = (fcc_ajax.settings && fcc_ajax.settings.omega3_target) || 0.5;
    var omega3Pct    = Math.round((d.omega3_g / omega3Target) * 100);
    $('#bar-protein-pct').text(d.prot_pct + '%');
    $('#bar-carbs-pct').text(d.carbs_pct + '%');
    $('#bar-fat-pct').text(d.fat_pct + '%');
    // Delay so slideDown has started and the browser renders the element before animating
    setTimeout(function () {
      setBar('#bar-protein', d.prot_pct);
      setBar('#bar-carbs',   d.carbs_pct);
      setBar('#bar-fat',     d.fat_pct);
      setBar('#bar-omega3',  omega3Pct);
    }, 350);

    // Daily Values
    $('#dv-calories').text(d.dv_calories + '%');
    $('#dv-protein').text(d.dv_protein  + '%');
    $('#dv-carbs').text(d.dv_carbs    + '%');
    $('#dv-fat').text(d.dv_fat      + '%');
    $('#dv-fiber').text(d.dv_fiber    + '%');
    $('#dv-sugar').text(d.dv_sugar    + '%');

    // Health Insight — omega-3, mercury, health tip
    $('#res-omega3').text(d.omega3_g + 'g');
    $('#res-omega3-pct').text(omega3Pct + '%');
    $('#res-omega3-hint').text(d.omega3_g + 'g of ' + omega3Target + 'g daily target (NHS/WHO)');
    var mercuryLabels = { low: 'Low Mercury', moderate: 'Moderate Mercury', high: 'High Mercury' };
    $('#res-mercury')
      .text(mercuryLabels[d.mercury] || d.mercury)
      .removeClass('mercury-low mercury-moderate mercury-high')
      .addClass('mercury-' + d.mercury);

    // Allergen badges
    var allergenStr = d.allergens || '';
    var $aw = $('#res-allergens').empty();
    if (!allergenStr || allergenStr === 'None') {
      $aw.html('<span class="fcc-allergen-badge fcc-ab-none">No Major Allergens</span>');
    } else {
      var allergenLabels = { Fish: 'Fish', Crustaceans: 'Crustaceans', Molluscs: 'Molluscs', Gluten: 'Gluten' };
      allergenStr.split('+').forEach(function (p) {
        var key = p.trim();
        var cls = 'fcc-ab-' + key.toLowerCase();
        $aw.append('<span class="fcc-allergen-badge ' + cls + '">' + escHtml(allergenLabels[key] || key) + '</span>');
      });
    }

    // UK seasonal availability
    var season = d.season || 'All year';
    var inSeason = (function (s) {
      if (s === 'All year') return true;
      var months = { Jan:1,Feb:2,Mar:3,Apr:4,May:5,Jun:6,Jul:7,Aug:8,Sep:9,Oct:10,Nov:11,Dec:12 };
      var parts = s.split('–'); // en dash
      if (parts.length !== 2) return true;
      var start = months[parts[0].trim()];
      var end   = months[parts[1].trim()];
      if (!start || !end) return true;
      var m = new Date().getMonth() + 1;
      return start <= end ? (m >= start && m <= end) : (m >= start || m <= end);
    }(season));
    $('#res-season').html(
      '<span class="fcc-season-text">' + escHtml(season) + '</span>' +
      '<small class="fcc-season-status ' + (inSeason ? 'in-season' : 'off-season') + '">' +
        (inSeason ? '✓ In season now' : 'Out of season') +
      '</small>'
    );

    // Print marketing section — populate dynamic fields
    $('#fcc-pm-fish-name').text(d.food_name);
    $('#fcc-pm-season-name').text(d.food_name);
    $('#fcc-pm-season').css('display', inSeason ? '' : 'none');

    // Caviar vs other seafood delivery messaging
    var isCaviar = /caviar|sturgeon|beluga|oscietra|sevruga|salmon\s*roe|keta\s*roe|keta/i.test(d.food_name + ' ' + (d.category || ''));
    var pdfCfg = (fcc_ajax && fcc_ajax.pdf) || {};
    var buySubText = isCaviar
      ? (pdfCfg.caviar_subtitle  || 'Order from Fishmonger London · Dispatched within 24 hours · UK-wide delivery')
      : (pdfCfg.seafood_subtitle || 'Order directly from Fishmonger London · Next-day wholesale delivery in Greater London');
    $('.fcc-pm-buy-sub').text(buySubText);

    // Sustainability / eco badge
    var ecoRating = d.eco_rating || 'ok';
    var ecoSource = d.eco_source || 'wild';
    var ecoBadges = {
      good: {
        wild:   { label: 'MSC Certified',      cls: 'eco-good',  icon: 'fa-leaf'         },
        farmed: { label: 'Sustainably Farmed',  cls: 'eco-good',  icon: 'fa-leaf'         },
        mixed:  { label: 'Sustainably Sourced', cls: 'eco-good',  icon: 'fa-leaf'         },
      },
      ok:    { label: 'Responsibly Sourced',  cls: 'eco-ok',    icon: 'fa-circle-check'  },
      avoid: { label: 'Avoid',               cls: 'eco-avoid', icon: 'fa-circle-xmark'  },
    };
    var ecoBadge = (ecoRating === 'good' ? ecoBadges.good[ecoSource] : ecoBadges[ecoRating])
                  || ecoBadges.ok;
    var ecoSourceLabel = ecoSource.charAt(0).toUpperCase() + ecoSource.slice(1) + ' caught';
    if (ecoSource === 'farmed') { ecoSourceLabel = 'Farmed'; }
    if (ecoSource === 'mixed')  { ecoSourceLabel = 'Wild & Farmed'; }
    $('#res-eco').html(
      '<span class="fcc-eco-badge ' + ecoBadge.cls + '">' +
        '<i class="fas ' + ecoBadge.icon + '"></i> ' + escHtml(ecoBadge.label) +
      '</span>' +
      '<small class="fcc-eco-source">' + escHtml(ecoSourceLabel) + '</small>'
    );

    var tip = (selectedFood && selectedFood.tip) || d.health_tip || '';
    $('#res-health-tip').text(tip);

    // Apply display settings
    var cfg = fcc_ajax.settings || {};
    $('#res-allergens').closest('.fcc-info-item').toggle(cfg.show_allergens !== 0);
    $('#res-eco').closest('.fcc-info-item').toggle(cfg.show_eco !== 0);
    $('#res-season').closest('.fcc-info-item').toggle(cfg.show_season !== 0);
    $('#res-mercury').toggle(cfg.show_mercury !== 0);
    $('#res-health-tip').toggle(cfg.show_health_tips !== 0);

    $('#fcc-results').slideDown(300);
  }

  /* ══════════════════════════════════════════════════════════════
     COMPARE TWO FISH
  ══════════════════════════════════════════════════════════════ */
  let compareSlots = [null, null];

  function isSameCompareItem(a, b) {
    return a && b &&
      a.food_id   === b.food_id &&
      a.serving_g === b.serving_g &&
      a.method_label === b.method_label;
  }

  $(document).on('click', '#fcc-add-to-compare', function () {
    if (!lastResult) {
      pulse('#fcc-food-search', 'Calculate a seafood item first.');
      return;
    }

    var slot;
    if (compareSlots[0] === null) {
      slot = 0;
    } else if (compareSlots[1] === null) {
      slot = 1;
    } else {
      slot = 0;
      compareSlots[1] = null;
    }

    // Block identical item (same fish + same serving + same method)
    var otherSlot = slot === 0 ? 1 : 0;
    if (isSameCompareItem(lastResult, compareSlots[otherSlot])) {
      var $btn = $(this);
      var savedHtml = $btn.html();
      $btn.html('<i class="fas fa-xmark"></i> Already added — change serving or method')
          .css({ background: '#ef4444', borderColor: '#ef4444' })
          .prop('disabled', true);
      setTimeout(function () {
        $btn.html(savedHtml).css({ background: '', borderColor: '' }).prop('disabled', false);
        updateCompareBtnLabel();
      }, 2000);
      return;
    }

    compareSlots[slot] = Object.assign({}, lastResult);
    renderCompareSlots();

    // Flash feedback
    var $btn = $(this);
    var label = slot === 0 ? '✓ Added as Item A' : '✓ Added as Item B';
    $btn.html('<i class="fas fa-check"></i> ' + label).prop('disabled', true);
    setTimeout(function () {
      $btn.prop('disabled', false);
      updateCompareBtnLabel();
    }, 1500);

    if (compareSlots[0] && compareSlots[1]) {
      setTimeout(function () {
        $('.fcc-tab[data-tab="compare"]').trigger('click');
      }, 600);
    }
  });

  $(document).on('click', '#fcc-clear-compare', function () {
    compareSlots = [null, null];
    renderCompareSlots();
  });

  function updateCompareBtnLabel() {
    var $btn = $('#fcc-add-to-compare');
    if (compareSlots[0] !== null && compareSlots[1] !== null) {
      $btn.html('<i class="fas fa-scale-balanced"></i> Replace Item A');
    } else if (compareSlots[0] !== null) {
      $btn.html('<i class="fas fa-scale-balanced"></i> Add as Item B');
    } else {
      $btn.html('<i class="fas fa-scale-balanced"></i> Compare');
    }
  }

  function updateCompareBadge() {
    var count = (compareSlots[0] ? 1 : 0) + (compareSlots[1] ? 1 : 0);
    var $badge = $('#fcc-compare-badge');
    if (count === 0) {
      $badge.hide().text('');
    } else {
      $badge.show().text(count);
    }
  }

  function renderCompareSlots() {
    ['a', 'b'].forEach(function (letter, i) {
      var d = compareSlots[i];
      var $slot = $('#compare-slot-' + letter);
      if (!d) {
        $slot.addClass('empty').removeClass('filled')
          .html('<i class="fas fa-fish"></i><span>Item ' + letter.toUpperCase() + ' — not selected</span>');
      } else {
        $slot.removeClass('empty').addClass('filled')
          .html(
            '<strong>' + escHtml(d.food_name) + '</strong>' +
            '<span>' + d.serving_g + 'g · ' + escHtml(d.method_label || '') + '</span>' +
            '<em>' + d.total_cal + ' kcal</em>'
          );
      }
    });

    $('#fcc-clear-compare').toggle(compareSlots[0] !== null || compareSlots[1] !== null);

    if (compareSlots[0] && compareSlots[1]) {
      renderCompareTable();
      $('#fcc-compare-table').show();
    } else {
      $('#fcc-compare-table').hide();
    }

    updateCompareBadge();
    updateCompareBtnLabel();
  }

  function renderCompareTable() {
    var a = compareSlots[0];
    var b = compareSlots[1];
    var mercOrder = { low: 0, moderate: 1, high: 2 };

    $('#cmp-head-a').text(a.food_name + ' (' + a.serving_g + 'g)');
    $('#cmp-head-b').text(b.food_name + ' (' + b.serving_g + 'g)');

    var rows = [
      { label: 'Calories', va: a.total_cal,  vb: b.total_cal,  unit: 'kcal', lowerWins: true  },
      { label: 'Protein',  va: a.protein_g,  vb: b.protein_g,  unit: 'g',    lowerWins: false },
      { label: 'Carbs',    va: a.carbs_g,    vb: b.carbs_g,    unit: 'g',    lowerWins: true  },
      { label: 'Fat',      va: a.fat_g,      vb: b.fat_g,      unit: 'g',    lowerWins: true  },
      { label: 'Fiber',    va: a.fiber_g,    vb: b.fiber_g,    unit: 'g',    lowerWins: false },
      { label: 'Sugar',    va: a.sugar_g,    vb: b.sugar_g,    unit: 'g',    lowerWins: true  },
      { label: 'Omega-3',  va: a.omega3_g,   vb: b.omega3_g,   unit: 'g',    lowerWins: false },
      { label: 'Mercury',
        va: mercOrder[a.mercury] || 0, vb: mercOrder[b.mercury] || 0,
        unit: null, lowerWins: true, displayA: a.mercury, displayB: b.mercury },
    ];

    var html = '';
    rows.forEach(function (row) {
      var winA = '', winB = '';
      if (row.va !== row.vb) {
        var aWins = row.lowerWins ? row.va < row.vb : row.va > row.vb;
        if (aWins) { winA = ' cmp-win'; } else { winB = ' cmp-win'; }
      }
      var dispA = row.displayA !== undefined ? row.displayA : (row.va + (row.unit ? ' ' + row.unit : ''));
      var dispB = row.displayB !== undefined ? row.displayB : (row.vb + (row.unit ? ' ' + row.unit : ''));
      html +=
        '<tr>' +
          '<td class="cmp-label-col">' + escHtml(row.label) + '</td>' +
          '<td class="cmp-cell' + winA + '">' + escHtml(String(dispA)) + '</td>' +
          '<td class="cmp-cell' + winB + '">' + escHtml(String(dispB)) + '</td>' +
        '</tr>';
    });

    $('#cmp-body').html(html);
  }

  /* ══════════════════════════════════════════════════════════════
     ADD TO MEAL TRACKER
  ══════════════════════════════════════════════════════════════ */
  /* ── Meal persistence helpers ──────────────────────────────────────────── */
  var saveTimer = null;

  function todayStr() {
    return new Date().toISOString().slice(0, 10);
  }

  function saveMeal() {
    var payload = JSON.stringify({ date: todayStr(), items: mealItems });
    try { localStorage.setItem('fcc_meal', payload); } catch(e) {}
    if (!fcc_ajax.user_logged_in) { showSaveStatus('saved'); return; }
    showSaveStatus('saving');
    $.post(fcc_ajax.ajax_url, {
      action: 'fcc_save_meal',
      nonce:  fcc_ajax.nonce,
      items:  payload,
    }).done(function(r) {
      showSaveStatus(r.success ? 'saved' : 'error');
    }).fail(function() { showSaveStatus('error'); });
  }

  function debouncedSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveMeal, 700);
  }

  function showSaveStatus(s) {
    var $el = $('#fcc-save-status');
    $el.removeClass('fss-saving fss-saved fss-error');
    if (s === 'saving') {
      $el.addClass('fss-saving').html('<i class="fas fa-circle-notch fa-spin"></i> Saving…').show();
    } else if (s === 'saved') {
      $el.addClass('fss-saved').html('<i class="fas fa-check"></i> Saved').show();
      setTimeout(function() { $el.fadeOut(400); }, 2200);
    } else {
      $el.addClass('fss-error').html('<i class="fas fa-triangle-exclamation"></i> Save failed').show();
    }
  }

  function loadMeal() {
    var today = todayStr();
    var loaded = null, source = '';
    if (fcc_ajax.user_logged_in && fcc_ajax.user_meal &&
        fcc_ajax.user_meal.date === today && fcc_ajax.user_meal.items && fcc_ajax.user_meal.items.length) {
      loaded = fcc_ajax.user_meal.items;
      source = 'account';
    }
    if (!loaded) {
      try {
        var raw = localStorage.getItem('fcc_meal');
        if (raw) {
          var p = JSON.parse(raw);
          if (p && p.date === today && p.items && p.items.length) { loaded = p.items; source = 'local'; }
        }
      } catch(e) {}
    }
    if (loaded && loaded.length) {
      mealItems = loaded;
      renderMealList();
      var icon = source === 'account' ? 'fa-user-check' : 'fa-clock-rotate-left';
      var msg  = source === 'account' ? 'Meal log restored from your account' : 'Meal log restored from this browser';
      $('#fcc-persist-text').html('<i class="fas ' + icon + '"></i> ' + msg);
      $('#fcc-persist-notice').show();
    }
  }

  /* ── Add to meal ─────────────────────────────────────────────────────────── */
  $(document).on('click', '#fcc-add-to-meal', function () {
    if (!lastResult) return;
    var item = {
      id:      Date.now(),
      name:    lastResult.food_name,
      serving: lastResult.serving_g,
      cal:     lastResult.total_cal,
      protein: lastResult.protein_g,
      carbs:   lastResult.carbs_g,
      fat:     lastResult.fat_g,
      omega3:  lastResult.omega3_g   || 0,
      fiber:   lastResult.fiber_g    || 0,
      method:  lastResult.method_label || '',
    };
    mealItems.push(item);
    renderMealList();
    debouncedSave();
    $('.fcc-tab[data-tab="meal-tracker"]').trigger('click');
  });

  /* ── Render meal list ────────────────────────────────────────────────────── */
  function renderMealList() {
    var $list = $('#fcc-meal-list').empty();
    var dateLabel = new Date().toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
    $('#fcc-meal-date').text('Today — ' + dateLabel);

    if (!mealItems.length) {
      $list.html(
        '<div class="fcc-empty-state">' +
          '<i class="fas fa-fish"></i>' +
          '<p>No seafood added yet.<br>Calculate an item in the <strong>Seafood Calculator</strong> tab,<br>then click <strong>Add to Meal</strong>.</p>' +
        '</div>'
      );
      $('#fcc-meal-totals').hide();
      return;
    }

    mealItems.forEach(function (item) {
      var methHtml  = item.method  ? '<span class="fcc-mi-tag">' + escHtml(item.method) + '</span>' : '';
      var o3Html    = (item.omega3 > 0) ? '<span class="fcc-mi-omega3">Ω-3 ' + item.omega3 + 'g</span>' : '';
      $list.append(
        '<div class="fcc-meal-item" data-id="' + item.id + '">' +
          '<div class="fcc-meal-icon"><i class="fas fa-fish"></i></div>' +
          '<div class="fcc-meal-info">' +
            '<div class="fcc-meal-name">' + escHtml(item.name) + '</div>' +
            '<div class="fcc-meal-meta">' +
              '<span class="fcc-mi-serving">' + item.serving + 'g</span>' +
              methHtml +
              '<span class="fcc-mi-macro">P ' + item.protein + 'g</span>' +
              '<span class="fcc-mi-macro">C ' + item.carbs + 'g</span>' +
              '<span class="fcc-mi-macro">F ' + item.fat + 'g</span>' +
              o3Html +
            '</div>' +
          '</div>' +
          '<div class="fcc-meal-cal">' + item.cal + '<small>kcal</small></div>' +
          '<button class="fcc-meal-remove" title="Remove item"><i class="fas fa-xmark"></i></button>' +
        '</div>'
      );
    });

    var totals = mealItems.reduce(function (acc, it) {
      acc.cal    += it.cal    || 0;
      acc.prot   += it.protein || 0;
      acc.carbs  += it.carbs  || 0;
      acc.fat    += it.fat    || 0;
      acc.omega3 += it.omega3 || 0;
      return acc;
    }, { cal: 0, prot: 0, carbs: 0, fat: 0, omega3: 0 });

    $('#total-calories').text(round1(totals.cal));
    $('#total-protein').text(round1(totals.prot)   + 'g');
    $('#total-carbs').text(round1(totals.carbs)  + 'g');
    $('#total-fat').text(round1(totals.fat)    + 'g');
    $('#total-omega3').text(round1(totals.omega3) + 'g');

    var omega3Target = (fcc_ajax.settings && fcc_ajax.settings.omega3_target) || 0.5;
    var o3pct = Math.min(Math.round((totals.omega3 / omega3Target) * 100), 100);
    $('#fcc-meal-omega3-bar').css('width', o3pct + '%');
    $('#fcc-meal-omega3-pct').text(o3pct + '%');

    $('#fcc-meal-totals').show();
  }

  /* ── Remove / clear ──────────────────────────────────────────────────────── */
  $(document).on('click', '.fcc-meal-remove', function () {
    var id = parseInt($(this).closest('.fcc-meal-item').data('id'));
    mealItems = mealItems.filter(function (it) { return it.id !== id; });
    renderMealList();
    debouncedSave();
  });

  $(document).on('click', '#fcc-clear-meal', function () {
    if (!confirm('Clear all meals from today\'s log?')) return;
    mealItems = [];
    renderMealList();
    saveMeal();
  });

  /* ══════════════════════════════════════════════════════════════
     PRINT & SHARE
  ══════════════════════════════════════════════════════════════ */
  $(document).on('click', '#fcc-print-btn', function () {
    if (!lastResult) {
      pulse('#fcc-food-search', 'Calculate a seafood item first.');
      return;
    }

    var origTitle = document.title;
    document.title = lastResult.food_name + ' — Nutrition Facts | Fishmonger London';

    // Move the whole #fcc-wrapper to a direct child of body.
    // This eliminates the gap from theme wrapper offsets while keeping all
    // #fcc-wrapper .xxx CSS rules intact (scoping is preserved).
    var $wrapper = $('#fcc-wrapper');
    var $placeholder = $('<span id="fcc-print-placeholder" style="display:none"></span>');
    $wrapper.before($placeholder);
    $('body').append($wrapper);

    // Hide body children except #fcc-wrapper and the theme header/nav
    // (header stays visible so its links appear in the PDF).
    var hidden = [];
    $('body').children().not('#fcc-wrapper').each(function () {
      var combo = ((this.id || '') + ' ' + (this.className || '')).toLowerCase();
      var isHeader = this.tagName.toLowerCase() === 'header' ||
                     combo.indexOf('masthead') !== -1 ||
                     combo.indexOf('site-header') !== -1 ||
                     combo.indexOf('site-nav') !== -1;
      if (!isHeader && $(this).css('display') !== 'none') {
        $(this).css('display', 'none');
        hidden.push(this);
      }
    });

    // Hide the calculator UI inside #fcc-wrapper (everything except #fcc-results)
    // so it doesn't generate blank pages.
    var hiddenInner = [];
    $('#fcc-results').siblings().each(function () {
      if ($(this).css('display') !== 'none') {
        $(this).css('display', 'none');
        hiddenInner.push(this);
      }
    });

    window.print();

    document.title = origTitle;
    $(hidden).css('display', '');
    $(hiddenInner).css('display', '');
    $placeholder.before($wrapper);
    $placeholder.remove();
  });

  $(document).on('click', '#fcc-share-btn', function () {
    if (!lastResult || !selectedFood) {
      pulse('#fcc-food-search', 'Calculate a seafood item first.');
      return;
    }
    var fishSlug = (selectedFood.name || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    var params = new URLSearchParams({
      fcc_fish:    fishSlug,
      fcc_food:    selectedFood.id,
      fcc_serving: $('#fcc-serving-size').val() || 100,
      fcc_unit:    $('#fcc-serving-unit').val()  || 'g',
      fcc_method:  $('#fcc-method').val()        || 'baked',
    });
    var url      = window.location.href.split('?')[0] + '?' + params.toString();
    var $btn     = $(this);
    var origHtml = $btn.html();

    function showCopied() {
      $btn.html('<i class="fas fa-check"></i> Copied!');
      setTimeout(function () { $btn.html(origHtml); }, 2500);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(showCopied).catch(function () {
        fallbackCopy(url, showCopied);
      });
    } else {
      fallbackCopy(url, showCopied);
    }
  });

  function fallbackCopy(text, cb) {
    var el = document.createElement('textarea');
    el.value = text;
    el.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
    document.body.appendChild(el);
    el.focus();
    el.select();
    try { document.execCommand('copy'); cb(); } catch (e) { /* silent fail */ }
    document.body.removeChild(el);
  }

  /* ══════════════════════════════════════════════════════════════
     HELPERS
  ══════════════════════════════════════════════════════════════ */

  function animateNumber(selector, from, to, duration) {
    const $el = $(selector);
    const start = performance.now();
    function step(now) {
      const elapsed  = now - start;
      const progress = Math.min(elapsed / duration, 1);
      const eased    = 1 - Math.pow(1 - progress, 3);
      $el.text(Math.round(from + (to - from) * eased));
      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function setBar(selector, pct) {
    $(selector).css('width', Math.min(pct, 100) + '%');
  }

  function setLoading($btn, loading) {
    if (loading) {
      $btn.data('orig-html', $btn.html());
      $btn.html('<span class="fcc-loader"></span> Calculating…').prop('disabled', true);
    } else {
      $btn.html($btn.data('orig-html')).prop('disabled', false);
    }
  }

  function round1(n) { return Math.round(n * 10) / 10; }

  function escHtml(str) {
    return $('<div>').text(str).html();
  }

  function pulse($el, msg) {
    $($el)
      .css({ outline: '2px solid #ef4444', transition: 'none' })
      .attr('placeholder', msg);
    setTimeout(function () {
      $($el).css('outline', '').attr('placeholder', 'Type a seafood name, e.g. Salmon, Cod, Prawns…');
    }, 2000);
  }

  /* ══════════════════════════════════════════════════════════════
     DOM READY — auto-load shared URL + restore meal
  ══════════════════════════════════════════════════════════════ */
  $(function () {
    // Restore persisted meal log
    loadMeal();

    // Trending strip — inject below filter row if data present
    var trending = fcc_ajax.trending || [];
    if (trending.length) {
      var chips = trending.map(function (t) {
        return '<button class="fcc-trend-chip" data-food-id="' + parseInt(t.food_id, 10) + '">' +
               '🔥 ' + escHtml(t.food_name) +
               '</button>';
      }).join('');
      var strip = '<div class="fcc-trending-strip" id="fcc-trending-strip">' +
                  '<span class="fcc-trend-label">Trending:</span>' + chips + '</div>';
      $('#fcc-filter-row').after(strip);
    }

    $(document).on('click', '.fcc-trend-chip', function () {
      var foodId = parseInt($(this).data('food-id'), 10);
      var food   = (fcc_ajax.foods || []).find(function (f) { return f.id === foodId; });
      if (!food) return;
      selectedFood = food;
      $('#fcc-food-search').val(food.name);
      closeSuggestions();
      $('#fcc-selected-name').text(food.name);
      $('#fcc-serving-section').show();
      $('#fcc-calculate-btn').trigger('click');
      $('.fcc-trend-chip').removeClass('active');
      $(this).addClass('active');
      trackSearchEvent(food.id, food.name);
    });

    // Auto-load from shared URL
    var params = new URLSearchParams(window.location.search);
    var foodId = parseInt(params.get('fcc_food'), 10);
    if (!foodId) return;
    var food = (fcc_ajax.foods || []).find(function (f) { return f.id === foodId; });
    if (!food) return;
    selectedFood = food;
    $('#fcc-food-search').val(food.name);
    $('#fcc-selected-name').text(food.name);
    $('#fcc-serving-size').val(parseFloat(params.get('fcc_serving')) || 100);
    $('#fcc-serving-unit').val(params.get('fcc_unit')   || 'g');
    $('#fcc-method').val(params.get('fcc_method')       || 'baked');
    $('#fcc-serving-section').show();
    $('#fcc-calculate-btn').trigger('click');
  });

})(jQuery);
