package com.upsl.admin;

import android.os.Bundle;
import android.os.Handler;
import android.text.Editable;
import android.text.InputType;
import android.text.TextWatcher;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.AbsListView;
import android.widget.AdapterView;
import android.widget.BaseAdapter;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ListView;
import android.widget.TextView;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Paginated list screen: optional search box and filter pills above a list that loads the next
 * page while scrolling. Subclasses provide the endpoint, query and row view.
 */
public abstract class ListScreen extends Screen {
    protected final List<JSONObject> items = new ArrayList<JSONObject>();
    protected ListView list;
    protected LinearLayout header;
    protected EditText search;
    protected JSONObject lastMeta;
    private Adapter adapter;
    private LinearLayout footer;
    private FrameLayout listHolder;
    private int page = 0;
    private int pages = 1;
    private boolean loading;
    private int generation;
    private final Handler handler = new Handler();
    private Runnable pendingSearch;

    /** Path relative to /api/v1, e.g. "/admin/games". */
    protected abstract String endpoint();

    /** Extra query parameters (filters); search text is added as "q" automatically. */
    protected void query(Map<String, String> q) {
    }

    protected abstract View row(JSONObject item, int position);

    protected void onItem(JSONObject item) {
    }

    protected boolean onItemLong(JSONObject item) {
        return false;
    }

    protected String searchHint() {
        return null;
    }

    protected String emptyTitle() {
        return "لا توجد عناصر";
    }

    protected String emptyBody() {
        return "";
    }

    protected int emptyIcon() {
        return R.drawable.ic_list;
    }

    /** Page size requested from the server. */
    protected int perPage() {
        return 30;
    }

    /** Called after the first page arrives (e.g. to show totals). */
    protected void onLoaded(Api.Result r) {
    }

    @Override
    protected void build(Bundle saved) {
        LinearLayout col = Ui.vbox(this);
        header = Ui.vbox(this);
        header.setPadding(Ui.dp(this, 16), 0, Ui.dp(this, 16), 0);
        if (searchHint() != null) {
            search = Form.input(this, searchHint(), InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
            android.graphics.drawable.Drawable icon = Ui.icon(this, R.drawable.ic_search, Ui.MIST);
            int s = Ui.dp(this, 18);
            icon.setBounds(0, 0, s, s);
            search.setCompoundDrawables(null, null, icon, null);
            search.setCompoundDrawablePadding(Ui.dp(this, 10));
            search.setSingleLine(true);
            search.addTextChangedListener(new TextWatcher() {
                @Override
                public void beforeTextChanged(CharSequence s, int start, int count, int after) {
                }

                @Override
                public void onTextChanged(CharSequence s, int start, int before, int count) {
                }

                @Override
                public void afterTextChanged(Editable s) {
                    if (pendingSearch != null) {
                        handler.removeCallbacks(pendingSearch);
                    }
                    pendingSearch = new Runnable() {
                        @Override
                        public void run() {
                            reload();
                        }
                    };
                    handler.postDelayed(pendingSearch, 450);
                }
            });
            LinearLayout.LayoutParams p = Ui.fill();
            p.bottomMargin = Ui.dp(this, 10);
            header.addView(search, p);
        }
        buildHeader(header);
        col.addView(header, Ui.fill());

        listHolder = new FrameLayout(this);
        list = new ListView(this);
        list.setDivider(null);
        list.setDividerHeight(0);
        list.setSelector(android.R.color.transparent);
        list.setClipToPadding(false);
        list.setPadding(Ui.dp(this, 16), Ui.dp(this, 2), Ui.dp(this, 16), Ui.dp(this, 24));
        list.setScrollBarStyle(View.SCROLLBARS_OUTSIDE_OVERLAY);
        footer = Ui.vbox(this);
        footer.setGravity(Gravity.CENTER_HORIZONTAL);
        footer.setPadding(0, Ui.dp(this, 8), 0, Ui.dp(this, 8));
        list.addFooterView(footer, null, false);
        adapter = new Adapter();
        list.setAdapter(adapter);
        list.setOnItemClickListener(new AdapterView.OnItemClickListener() {
            @Override
            public void onItemClick(AdapterView<?> parent, View view, int position, long id) {
                if (position < items.size()) {
                    onItem(items.get(position));
                }
            }
        });
        list.setOnItemLongClickListener(new AdapterView.OnItemLongClickListener() {
            @Override
            public boolean onItemLongClick(AdapterView<?> parent, View view, int position, long id) {
                return position < items.size() && onItemLong(items.get(position));
            }
        });
        list.setOnScrollListener(new AbsListView.OnScrollListener() {
            @Override
            public void onScrollStateChanged(AbsListView view, int scrollState) {
            }

            @Override
            public void onScroll(AbsListView view, int first, int visible, int total) {
                if (!loading && page > 0 && page < pages && first + visible >= total - 4) {
                    loadPage(page + 1);
                }
            }
        });
        listHolder.addView(list, new FrameLayout.LayoutParams(Ui.MATCH, Ui.MATCH));
        col.addView(listHolder, new LinearLayout.LayoutParams(Ui.MATCH, 0, 1f));
        setBody(col);
        afterBuild();
        reload();
    }

    /** Subclasses add filter pills or summary cards here. */
    protected void buildHeader(LinearLayout header) {
    }

    protected void afterBuild() {
    }

    public void reload() {
        generation++;
        items.clear();
        adapter.notifyDataSetChanged();
        page = 0;
        pages = 1;
        loading = false;
        loadPage(1);
    }

    /** Re-renders rows without reloading (after a local change). */
    public void refreshRows() {
        adapter.notifyDataSetChanged();
    }

    private void loadPage(final int p) {
        loading = true;
        final int gen = generation;
        Map<String, String> q = new LinkedHashMap<String, String>();
        q.put("page", String.valueOf(p));
        q.put("per_page", String.valueOf(perPage()));
        if (search != null && search.getText().toString().trim().length() > 0) {
            q.put("q", search.getText().toString().trim());
        }
        query(q);
        if (p == 1) {
            showListState(Ui.spinner(this), true);
        } else {
            footer.removeAllViews();
            footer.addView(Ui.spinner(this));
        }
        api.get(endpoint(), q, new Api.Callback() {
            @Override
            public void done(Api.Result r) {
                if (!alive() || gen != generation) {
                    return;
                }
                loading = false;
                footer.removeAllViews();
                if (!r.ok) {
                    if (p == 1) {
                        final Api.Error e = r.error;
                        showListState(Ui.state(ListScreen.this, e != null && e.status == 0 ? R.drawable.ic_wifi_off : R.drawable.ic_alert, Ui.BAD,
                                e != null && e.status == 403 ? "ليست لديك صلاحية" : "تعذر التحميل", e == null ? "" : e.getMessage(),
                                "إعادة المحاولة", new View.OnClickListener() {
                                    @Override
                                    public void onClick(View v) {
                                        reload();
                                    }
                                }), false);
                    } else {
                        TextView retry = Ui.button(ListScreen.this, "تعذر تحميل المزيد — إعادة المحاولة", 0, Ui.BTN_GHOST, new View.OnClickListener() {
                            @Override
                            public void onClick(View v) {
                                loadPage(p);
                            }
                        });
                        footer.addView(retry);
                    }
                    return;
                }
                JSONArray arr = r.data instanceof JSONArray ? (JSONArray) r.data : extractArray(r);
                List<JSONObject> got = Json.list(arr);
                items.addAll(got);
                page = p;
                lastMeta = r.meta;
                pages = r.meta != null ? Json.num(r.meta, "pages", 1) : 1;
                adapter.notifyDataSetChanged();
                if (p == 1) {
                    onLoaded(r);
                    if (items.isEmpty()) {
                        showListState(Ui.state(ListScreen.this, emptyIcon(), Ui.ACCENT, emptyTitle(), emptyBody(), null, null), false);
                    } else {
                        showListState(null, false);
                    }
                }
                if (page >= pages && items.size() > 8) {
                    TextView end = Ui.small(ListScreen.this, "— " + Fmt.count(r.meta != null ? Json.num(r.meta, "total", items.size()) : items.size(), "عنصر", "عنصران", "عناصر") + " —");
                    end.setGravity(Gravity.CENTER);
                    end.setTextAlignment(View.TEXT_ALIGNMENT_CENTER);
                    footer.addView(end, Ui.fill());
                }
            }
        });
    }

    /** For endpoints whose data is an object holding the list (override when needed). */
    protected JSONArray extractArray(Api.Result r) {
        return new JSONArray();
    }

    private View stateView;

    private void showListState(View v, boolean overlay) {
        if (stateView != null) {
            listHolder.removeView(stateView);
            stateView = null;
        }
        if (v != null) {
            FrameLayout wrap = new FrameLayout(this);
            if (v instanceof android.widget.ProgressBar) {
                wrap.addView(v, new FrameLayout.LayoutParams(Ui.dp(this, 40), Ui.dp(this, 40), Gravity.CENTER));
            } else {
                wrap.addView(v, new FrameLayout.LayoutParams(Ui.MATCH, Ui.WRAP, Gravity.CENTER));
            }
            stateView = wrap;
            listHolder.addView(wrap, new FrameLayout.LayoutParams(Ui.MATCH, Ui.MATCH));
        }
        list.setVisibility(v == null || overlay ? View.VISIBLE : View.INVISIBLE);
    }

    private final class Adapter extends BaseAdapter {
        @Override
        public int getCount() {
            return items.size();
        }

        @Override
        public Object getItem(int position) {
            return items.get(position);
        }

        @Override
        public long getItemId(int position) {
            return position;
        }

        @Override
        public View getView(int position, View convertView, ViewGroup parent) {
            View v = row(items.get(position), position);
            if (v.getLayoutParams() == null || !(v.getLayoutParams() instanceof AbsListView.LayoutParams)) {
                // keep the row's bottom margin by wrapping it (ListView rows ignore margins)
                FrameLayout w = new FrameLayout(ListScreen.this);
                int mb = 0;
                if (v.getLayoutParams() instanceof LinearLayout.LayoutParams) {
                    mb = ((LinearLayout.LayoutParams) v.getLayoutParams()).bottomMargin;
                }
                FrameLayout.LayoutParams p = new FrameLayout.LayoutParams(Ui.MATCH, Ui.WRAP);
                p.bottomMargin = mb;
                w.addView(v, p);
                w.setLayoutParams(new AbsListView.LayoutParams(Ui.MATCH, Ui.WRAP));
                w.setDescendantFocusability(ViewGroup.FOCUS_BLOCK_DESCENDANTS);
                return w;
            }
            return v;
        }
    }

    /** Builds a filter pill row whose selection reloads the list. */
    protected View filters(final List<String> labels, final int[] selected, final Runnable onChange) {
        final FrameLayout holder = new FrameLayout(this);
        renderFilters(holder, labels, selected, onChange);
        return holder;
    }

    private void renderFilters(final FrameLayout holder, final List<String> labels, final int[] selected, final Runnable onChange) {
        holder.removeAllViews();
        holder.addView(Ui.pillRow(this, labels, selected[0], new Ui.IntCallback() {
            @Override
            public void done(int value) {
                if (selected[0] != value) {
                    selected[0] = value;
                    renderFilters(holder, labels, selected, onChange);
                    onChange.run();
                }
            }
        }));
    }
}
