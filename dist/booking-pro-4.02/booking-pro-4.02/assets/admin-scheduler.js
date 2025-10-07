(function(){
  'use strict';

  const cfg = window.SBDP_ADMIN_SCHEDULER;
  const root = document.getElementById('sbdp-scheduler-app');

  if (!cfg || !root) {
    return;
  }

  const state = {
    date: new Date().toISOString().slice(0, 10),
    loading: false,
    error: '',
    data: { resources: [], events: [] }
  };

  const formatTime = function(value){
    if (!value) {
      return '';
    }
    const ts = Date.parse(value);
    if (Number.isNaN(ts)) {
      return value;
    }
    return new Date(ts).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  };

  const groupEvents = function(events){
    const map = {};
    (events || []).forEach(function(evt){
      const key = evt.resource && evt.resource.id ? evt.resource.id : 0;
      if (!map[key]) {
        map[key] = [];
      }
      map[key].push(evt);
    });
    Object.keys(map).forEach(function(key){
      map[key].sort(function(a,b){
        return Date.parse(a.start || 0) - Date.parse(b.start || 0);
      });
    });
    return map;
  };

  const renderTable = function(){
    const events = state.data.events || [];
    const grouped = groupEvents(events);
    const resources = state.data.resources || [];
    const resourceLookup = {};
    resources.forEach(function(res){
      resourceLookup[res.id] = {
        name: res.name,
        capacity: res.capacity || 0
      };
    });

    const container = document.createElement('div');
    container.className = 'sbdp-scheduler-table';

    const keys = Object.keys(grouped);
    if (!keys.length) {
      const empty = document.createElement('p');
      empty.className = 'sbdp-scheduler-empty';
      empty.textContent = 'Geen activiteiten voor deze dag.';
      container.appendChild(empty);
      return container;
    }

    keys.forEach(function(key){
      const section = document.createElement('div');
      section.className = 'sbdp-scheduler-section';

      const header = document.createElement('h3');
      if (key !== '0' && resourceLookup[key]) {
        const entry = resourceLookup[key];
        const capacityLabel = entry.capacity && entry.capacity > 0 ? ' (capaciteit ' + entry.capacity + ')' : '';
        header.textContent = entry.name + capacityLabel;
      } else {
        header.textContent = 'Niet toegewezen';
      }
      section.appendChild(header);

      const table = document.createElement('table');
      table.className = 'wp-list-table widefat fixed striped';
      const thead = document.createElement('thead');
      thead.innerHTML = '<tr><th>Tijd</th><th>Activiteit</th><th>Deelnemers</th><th>Klant</th><th>Status</th></tr>';
      table.appendChild(thead);

      const tbody = document.createElement('tbody');
      grouped[key].forEach(function(evt){
        const tr = document.createElement('tr');
        const link = evt.link ? '<a href="'+evt.link+'" target="_blank" rel="noopener">#'+evt.order_id+'</a>' : '';
        tr.innerHTML = [
          '<td>'+formatTime(evt.start)+' - '+formatTime(evt.end)+'</td>',
          '<td>'+ (evt.product_name || '-') +'<br><small>'+link+'</small></td>',
          '<td>'+ (evt.participants || 1) +'</td>',
          '<td>'+ (evt.customer || '-') +'</td>',
          '<td>'+ (evt.order_status || '-') +'</td>'
        ].join('');
        tbody.appendChild(tr);
      });
      table.appendChild(tbody);
      section.appendChild(table);
      container.appendChild(section);
    });

    return container;
  };

  const render = function(){
    root.innerHTML = '';
    const controls = document.createElement('div');
    controls.className = 'sbdp-scheduler-controls';

    const dateLabel = document.createElement('label');
    dateLabel.textContent = 'Kies datum';
    const dateInput = document.createElement('input');
    dateInput.type = 'date';
    dateInput.value = state.date;
    dateInput.addEventListener('change', function(){
      state.date = this.value || state.date;
      fetchData();
    });
    dateLabel.appendChild(dateInput);
    controls.appendChild(dateLabel);

    const refresh = document.createElement('button');
    refresh.className = 'button';
    refresh.textContent = 'Vernieuw';
    refresh.addEventListener('click', function(){
      fetchData(true);
    });
    controls.appendChild(refresh);

    root.appendChild(controls);

    if (state.loading) {
      const loading = document.createElement('p');
      loading.className = 'sbdp-scheduler-loading';
      loading.textContent = 'Bezig met laden...';
      root.appendChild(loading);
      return;
    }

    if (state.error) {
      const error = document.createElement('p');
      error.className = 'notice notice-error';
      error.textContent = state.error;
      root.appendChild(error);
    }

    const table = renderTable();
    root.appendChild(table);
  };

  const fetchData = function(force){
    if (state.loading && !force) {
      return;
    }
    state.loading = true;
    state.error = '';
    render();

    const url = cfg.endpoint + '?date=' + encodeURIComponent(state.date);
    fetch(url, {
      headers: {
        'X-WP-Nonce': cfg.nonce
      }
    })
      .then(function(res){
        if (!res.ok) {
          throw new Error('Kan planning niet laden');
        }
        return res.json();
      })
      .then(function(data){
        state.data = data || { resources: [], events: [] };
      })
      .catch(function(error){
        state.error = error.message || 'Onbekende fout';
      })
      .finally(function(){
        state.loading = false;
        render();
      });
  };

  fetchData();
})();
