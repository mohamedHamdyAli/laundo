{{--
    The panel's charts: ApexCharts (already on every panel page, so nothing new
    is loaded) drawn in validated colours and the panel's own ink.

    Colours are roles, set once here per theme and read by the charts at draw
    time — checked with the data-viz validator against the card surfaces
    (`#ffffff`, `#172033`):
      --viz-s1..s8   categorical, fixed order (series and services); slots 1–5
                     pass as a ring, which is what a donut is
      --viz-o1..o5   ordinal, one blue light→dark (the stages of an order); the
                     dark theme walks it the other way, dark→light, so its
                     faint end still clears the dark card
      --viz-other    «the rest», a grey that is no series
    Text is never the series colour: labels and values use --text-strong /
    --text-muted, and a coloured swatch beside them carries identity.

    Tooltips are built here, not by ApexCharts: its own tooltip writes names in
    with innerHTML, and a series name here is a translation any `language.update`
    account can edit. Everything that goes into one passes through `esc()`.
--}}
@once
    @push('styles')
        <style>
            body {
                --viz-s1: #2a78d6; --viz-s2: #eb6834; --viz-s3: #1baf7a; --viz-s4: #eda100;
                --viz-s5: #e87ba4; --viz-s6: #008300; --viz-s7: #4a3aa7; --viz-s8: #e34948;
                --viz-o1: #86b6ef; --viz-o2: #5598e7; --viz-o3: #2a78d6; --viz-o4: #1c5cab; --viz-o5: #104281;
                --viz-other: #c3c2b7;
            }
            body.theme-dark {
                --viz-s1: #3987e5; --viz-s2: #d95926; --viz-s3: #199e70; --viz-s4: #c98500;
                --viz-s5: #d55181; --viz-s6: #008300; --viz-s7: #9085e9; --viz-s8: #e66767;
                --viz-o1: #256abf; --viz-o2: #3987e5; --viz-o3: #6da7ec; --viz-o4: #9ec5f4; --viz-o5: #cde2fb;
                --viz-other: #5a6478;
            }

            .viz-canvas { width: 100%; min-height: 1px; }
            .viz-canvas .apexcharts-canvas { margin-inline: auto; }

            /* The legend is the chart's table: every value is here as text. */
            .viz-legend { list-style: none; margin: 0; padding: 0; display: grid; gap: .375rem; }
            .viz-legend li {
                display: grid; grid-template-columns: auto 1fr auto; align-items: center;
                gap: .5rem; font-size: .8125rem; color: var(--text-muted);
            }
            .viz-legend .viz-value { color: var(--text-strong); font-weight: 600; }
            .viz-legend .viz-share { color: var(--text-muted); font-weight: 400; margin-inline-start: .25rem; }
            .viz-swatch { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
            .viz-key { width: 14px; height: 2px; border-radius: 1px; display: inline-block; }

            .viz-legend-row { display: flex; flex-wrap: wrap; gap: .25rem 1rem; list-style: none; margin: 0 0 .25rem; padding: 0; }
            .viz-legend-row li { display: inline-flex; align-items: center; gap: .375rem; font-size: .8125rem; color: var(--text-muted); }

            .viz-tip {
                background: var(--surface-card); color: var(--text-strong);
                border: 1px solid var(--surface-border); border-radius: 8px;
                padding: .5rem .625rem; font-size: .8125rem; line-height: 1.5;
                box-shadow: 0 6px 18px rgba(0, 0, 0, .12);
            }
            .viz-tip-head { color: var(--text-muted); margin-bottom: .125rem; }
            .viz-tip-row { display: flex; align-items: center; gap: .5rem; }
            .viz-tip-row strong { font-weight: 600; }
            .viz-tip-row span:last-child { color: var(--text-muted); }
            /* ApexCharts wraps a custom tooltip in its own box; ours is the box. */
            .apexcharts-tooltip.apexcharts-theme-light,
            .apexcharts-tooltip.apexcharts-theme-dark { background: transparent !important; border: 0 !important; box-shadow: none !important; }

            .viz-empty { color: var(--text-muted); font-size: .875rem; text-align: center; padding: 1.5rem 0; margin: 0; }

            .viz-table summary { cursor: pointer; font-size: .8125rem; color: var(--brand-text); margin-top: .5rem; }
            .viz-table table { margin: .5rem 0 0; font-size: .8125rem; }
            .viz-table td, .viz-table th { font-variant-numeric: tabular-nums; }

            /* Ratings: bars drawn in HTML — five rows need no chart library. */
            .viz-bars { display: grid; gap: .5rem; }
            .viz-bar-row { display: grid; grid-template-columns: 3.25rem 1fr 2.5rem; align-items: center; gap: .75rem; font-size: .8125rem; }
            .viz-bar-label { color: var(--text-muted); white-space: nowrap; }
            .viz-bar-track { height: 10px; }
            .viz-bar-fill { height: 10px; min-width: 2px; background: var(--viz-s1); border-start-end-radius: 4px; border-end-end-radius: 4px; }
            .viz-bar-value { color: var(--text-strong); font-weight: 600; text-align: end; font-variant-numeric: tabular-nums; }
            /* Named rows (a service, a laundry): the label needs the room. */
            .viz-bars.is-named .viz-bar-row { grid-template-columns: minmax(5rem, 9rem) 1fr auto; }
            .viz-bars.is-named .viz-bar-label { overflow: hidden; text-overflow: ellipsis; }
            .viz-bars.is-named .viz-bar-value { white-space: nowrap; }

            /* A change against last month: sign and arrow carry it, the colour
               only repeats it. */
            .viz-delta { font-size: .75rem; color: var(--text-muted); display: block; margin-top: .125rem; }
            .viz-delta b { font-weight: 600; }
            .viz-delta.is-up b { color: var(--tone-ok); }
            .viz-delta.is-down b { color: var(--tone-bad); }

            /* Part to whole on one bar: segments with the 2px surface gap. */
            .viz-stack { display: flex; gap: 2px; height: 14px; margin: .25rem 0 .75rem; }
            .viz-stack span { display: block; height: 100%; min-width: 2px; }
            .viz-stack span:first-child { border-start-start-radius: 4px; border-end-start-radius: 4px; }
            .viz-stack span:last-child { border-start-end-radius: 4px; border-end-end-radius: 4px; }
        </style>
    @endpush

    @push('scripts')
        <script>
            window.laundoCharts = (function () {
                var drawn = [];

                function css(name) {
                    return getComputedStyle(document.body).getPropertyValue(name).trim();
                }

                function esc(value) {
                    return String(value).replace(/[&<>"']/g, function (c) {
                        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                    });
                }

                function data(id) {
                    var node = document.getElementById(id);
                    return node ? JSON.parse(node.textContent) : null;
                }

                function chrome() {
                    return {
                        fontFamily: 'inherit',
                        foreColor: css('--text-muted'),
                        background: 'transparent',
                        toolbar: { show: false },
                        zoom: { enabled: false },
                        parentHeightOffset: 0,
                        animations: { enabled: false },
                    };
                }

                function tipRow(color, value, name) {
                    return '<div class="viz-tip-row"><span class="viz-key" style="background:' + esc(color) + '"></span>'
                        + '<strong>' + esc(value) + '</strong><span>' + esc(name) + '</span></div>';
                }

                /** Draw now, and again with the other theme's colours when it changes. */
                function keep(element, build) {
                    if (!element || typeof ApexCharts === 'undefined') return;
                    var entry = { element: element, build: build, chart: null };
                    entry.chart = new ApexCharts(element, build());
                    entry.chart.render();
                    drawn.push(entry);
                }

                var dark = document.body.classList.contains('theme-dark');
                new MutationObserver(function () {
                    var now = document.body.classList.contains('theme-dark');
                    if (now === dark) return; // a modal opening is not a theme
                    dark = now;
                    drawn.forEach(function (entry) {
                        entry.chart.destroy();
                        entry.chart = new ApexCharts(entry.element, entry.build());
                        entry.chart.render();
                    });
                }).observe(document.body, { attributes: true, attributeFilter: ['class'] });

                /**
                 * A donut. `slices`: [{label, value, color}] where color is a CSS
                 * variable name. No numbers on the ring — the legend beside it
                 * has every value — and the total in the middle.
                 */
                /**
                 * Money as the panel writes it: Western digits (`-u-nu-latn`,
                 * as `moneyFormat()` does) and the install's currency.
                 */
                function money(locale, currency) {
                    var format = new Intl.NumberFormat((locale || 'en') + '-u-nu-latn', {
                        style: 'currency', currency: currency || 'EGP', minimumFractionDigits: 2,
                    });
                    return function (value) { return format.format(Number(value) || 0); };
                }

                function donut(element, slices, totalLabel, format) {
                    var money = !!format;
                    format = format || function (value) { return String(value); };
                    keep(element, function () {
                        var colors = slices.map(function (s) { return css(s.color); });
                        return {
                            chart: Object.assign(chrome(), { type: 'donut', height: 220 }),
                            series: slices.map(function (s) { return s.value; }),
                            labels: slices.map(function (s) { return s.label; }),
                            colors: colors,
                            legend: { show: false },
                            dataLabels: { enabled: false },
                            // The surface gap between slices, in the card's own colour.
                            stroke: { width: 2, colors: [css('--surface-card')] },
                            states: { hover: { filter: { type: 'lighten', value: .08 } }, active: { filter: { type: 'none' } } },
                            plotOptions: { pie: { expandOnClick: false, donut: {
                                size: '70%',
                                labels: {
                                    show: true,
                                    name: { show: true, color: css('--text-muted'), fontSize: '12px', offsetY: 18 },
                                    value: {
                                        // A sum of money is wider than a count; it has to fit the hole.
                                        show: true, color: css('--text-strong'), fontSize: money ? '16px' : '24px', fontWeight: 600, offsetY: -12,
                                        formatter: function (value) { return format(value); },
                                    },
                                    total: {
                                        show: true, showAlways: true, label: totalLabel, color: css('--text-muted'), fontSize: '12px',
                                        formatter: function (w) { return format(w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0)); },
                                    },
                                },
                            } } },
                            tooltip: {
                                custom: function (o) {
                                    return '<div class="viz-tip">' + tipRow(colors[o.seriesIndex], format(o.series[o.seriesIndex]), slices[o.seriesIndex].label) + '</div>';
                                },
                            },
                        };
                    });
                }

                /**
                 * Lines over days. `series`: [{name, values, color}]; `days`: the
                 * x labels. One crosshair, every series in its readout.
                 */
                /**
                 * A y-axis of whole numbers. Left to itself the axis puts ticks at
                 * halves when the counts are small, and rounding those for display
                 * reads «0, 1, 1, 2, 2» — one step per unit instead.
                 */
                function countAxis(values) {
                    var top = Math.max.apply(null, values.concat([0]));
                    var axis = {
                        min: 0, decimalsInFloat: 0,
                        labels: { formatter: function (v) { return Math.round(v); }, style: { colors: css('--text-muted') } },
                    };
                    if (top <= 5) {
                        axis.max = Math.max(top, 1);
                        axis.tickAmount = axis.max;
                    } else {
                        axis.forceNiceScale = true;
                    }
                    return axis;
                }

                function lines(element, days, series) {
                    keep(element, function () {
                        var colors = series.map(function (s) { return css(s.color); });
                        return {
                            chart: Object.assign(chrome(), { type: 'line', height: 240 }),
                            series: series.map(function (s) { return { name: s.name, data: s.values }; }),
                            colors: colors,
                            stroke: { width: 2, curve: 'straight', lineCap: 'round' },
                            markers: { size: 0, hover: { size: 5 }, strokeColors: css('--surface-card'), strokeWidth: 2 },
                            legend: { show: false },
                            dataLabels: { enabled: false },
                            grid: { borderColor: css('--surface-border'), strokeDashArray: 0, padding: { left: 8, right: 8 } },
                            xaxis: {
                                categories: days,
                                labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: css('--text-muted') } },
                                axisBorder: { color: css('--surface-border') },
                                axisTicks: { show: false },
                                crosshairs: { stroke: { color: css('--surface-border'), width: 1, dashArray: 0 } },
                                tooltip: { enabled: false },
                            },
                            yaxis: countAxis([].concat.apply([], series.map(function (s) { return s.values; }))),
                            tooltip: {
                                shared: true, intersect: false,
                                custom: function (o) {
                                    var html = '<div class="viz-tip"><div class="viz-tip-head">' + esc(days[o.dataPointIndex]) + '</div>';
                                    series.forEach(function (s, i) { html += tipRow(colors[i], s.values[o.dataPointIndex], s.name); });
                                    return html + '</div>';
                                },
                            },
                        };
                    });
                }

                /**
                 * Columns over days, one series. `labels` are the values already
                 * written the way the page writes them (money, say) for the tip.
                 */
                function columns(element, days, values, labels, name, color) {
                    keep(element, function () {
                        var fill = css(color);
                        return {
                            chart: Object.assign(chrome(), { type: 'bar', height: 240 }),
                            series: [{ name: name, data: values }],
                            colors: [fill],
                            plotOptions: { bar: { columnWidth: '42%', borderRadius: 4, borderRadiusApplication: 'end' } },
                            legend: { show: false },
                            dataLabels: { enabled: false },
                            states: { hover: { filter: { type: 'lighten', value: .08 } }, active: { filter: { type: 'none' } } },
                            grid: { borderColor: css('--surface-border'), strokeDashArray: 0, padding: { left: 8, right: 8 } },
                            xaxis: {
                                categories: days,
                                labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: css('--text-muted') } },
                                axisBorder: { color: css('--surface-border') },
                                axisTicks: { show: false },
                                tooltip: { enabled: false },
                            },
                            yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: css('--text-muted') } } },
                            tooltip: {
                                custom: function (o) {
                                    return '<div class="viz-tip"><div class="viz-tip-head">' + esc(days[o.dataPointIndex]) + '</div>'
                                        + tipRow(fill, labels[o.dataPointIndex], name) + '</div>';
                                },
                            },
                        };
                    });
                }

                return { data: data, donut: donut, lines: lines, columns: columns, money: money };
            })();
        </script>
    @endpush
@endonce
