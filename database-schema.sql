--
-- PostgreSQL database dump
--

SET statement_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET client_min_messages = warning;

--
-- Name: data; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA data;


SET search_path = data, pg_catalog;

--
-- Name: actgrazingdays(integer); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION actgrazingdays(planid integer, OUT rid integer, OUT padid integer, OUT name text, OUT area real, OUT qual real, OUT actmingd integer, OUT actmaxgd integer) RETURNS SETOF record
    LANGUAGE plpgsql STABLE
    AS $$
declare
  rec record;
  recov record;
  avgdays real;
  herdcnt integer;
  padcnt integer;
    
begin
  select into herdcnt count(*) from herds where plan=planid;

  select into padcnt count(*) from herd_rotations where plan=planid;
  
  select into recov round(min(minrecov::numeric)/(padcnt - herdcnt), 1) as avgmingraz,
                    round(max(maxrecov::numeric)/(padcnt - herdcnt), 1) as avgmaxgraz
    from plan_recovery where plan=planid;

  select into rec avg(round((a.plan_quality*b.area)::numeric, 1)) as avgdays
    from herd_rotations a, paddocks b
   where a.plan=planid and a.padid=b.id;

  avgdays := rec.avgdays;

  for rec in select a.id as rid,
                    a.padid as pad,
                    b.name,
                    round(b.area::numeric, 1) as area,
                    round((a.plan_quality*b.area)::numeric, 1) as qual,
                    round(a.plan_quality*b.area*recov.avgmingraz/avgdays) as actmingraz,
                    round(a.plan_quality*b.area*recov.avgmaxgraz/avgdays) as actmaxgraz
               from herd_rotations a, paddocks b
              where a.plan=planid and a.padid=b.id
  loop
    rid   := rec.rid;
    padid := rec.pad;
    name := rec.name;
    area := rec.area;
    qual := rec.qual;
    actMinGD := rec.actmingraz;
    actMaxGD := rec.actmaxgraz;
    return next;
  end loop;
   
  return;
end;
$$;


--
-- Name: countusagebymonth(integer); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION countusagebymonth(yrsback integer, OUT padid integer, OUT name text, OUT counts integer[], OUT total integer) RETURNS SETOF record
    LANGUAGE plpgsql STABLE ROWS 100
    AS $$
declare
  rec record;
  i integer;
  last text;
  lastid integer;
  dt timestamp;
  minyear integer;

begin
  minyear := extract(year from (now() - yrsback * interval '1 year'));
  for rec in select b.id, b.name, a.act_start, a.act_end
             from paddocks b left outer join herd_rotations a on a.padid=b.id 
             where act_start is null or extract(year from act_start) >= minyear
             order by b.id loop
    if last is null or last != rec.name then
      if last is not null then
        name := last;
        padid := lastid;
        total := 0;
        for i in 1..12 loop
          total := total + counts[i];
        end loop;
        return next;
      end if;
      for i in 1..12 loop
        counts[i] := 0;
      end loop;
      last := rec.name;
      lastid := rec.id;
    end if;

    dt := rec.act_start;
    loop
      exit when dt is null or dt > rec.act_end;
      i := extract(month from dt)::integer;
      counts[i] := counts[i] + 1;
      dt := dt + interval '1 month';
    end loop;
  end loop;

  if last is not null then
    name := last;
    padid := lastid;
    total := 0;
    for i in 1..12 loop
      total := total + counts[i];
    end loop;
    return next;
  end if;

  return;
end;
$$;


--
-- Name: daysavailablebymonth(integer, timestamp without time zone, timestamp without time zone); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION daysavailablebymonth(yr integer, start_dt timestamp without time zone, end_dt timestamp without time zone) RETURNS integer[]
    LANGUAGE plpgsql STABLE
    AS $$
declare
  m integer;
  ms timestamp;
  days integer[];
  
begin
  for m in 1 .. 12 loop
    ms := yr::text||'-'||m||'-01';
    days[m] := daysOverlap(ms, ms+interval '1 month', start_dt, end_dt);
  end loop;

  return days;
end;
$$;


--
-- Name: daysoverlap(timestamp without time zone, timestamp without time zone, timestamp without time zone, timestamp without time zone); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION daysoverlap(a_s timestamp without time zone, a_e timestamp without time zone, b_s timestamp without time zone, b_e timestamp without time zone) RETURNS integer
    LANGUAGE plpgsql IMMUTABLE
    AS $$
declare
  ts timestamp;
  te timestamp;
begin
  if (a_s, a_e) overlaps (b_s, b_e) then
    ts := case when a_s > b_s then a_s else b_s end;
    te := case when a_e < b_e then a_e else b_e end;
    return extract(day from te - ts);
  else
    return 0;
  end if;
end;
$$;


--
-- Name: getorderpaddocks(integer, integer[]); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION getorderpaddocks(planid integer, pids integer[] DEFAULT NULL::integer[]) RETURNS TABLE(seq integer, rid integer, padid integer, grazing_days integer, act_start date, act_end date)
    LANGUAGE plpgsql STABLE ROWS 100
    AS $$
begin

-- list
if pids is null then
  return query select (row_number() over())::integer as seq, aa.*
                 from (
                        select id::integer as rid, a.padid::integer, a.grazing_days::integer, a.act_start::date, a.act_end::date
                          from herd_rotations a
                         where plan=planid
                         order by coalesce(a.act_start, plan_start) asc
                      ) aa;

else
-- update
  return query select (row_number() over())::integer as seq, a.rid::integer, b.padid::integer, b.grazing_days::integer,
                      b.act_start::date, b.act_end::date
                 from ( select unnest(pids) as rid ) a,
                      ( select * from herd_rotations where plan=planid ) b
                where a.rid=b.id;
end if;

end;
$$;


--
-- Name: monthlysau(integer); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION monthlysau(planid integer, OUT herdid integer, OUT name text, OUT totsau double precision[]) RETURNS SETOF record
    LANGUAGE plpgsql STABLE
    AS $$
declare
  planrec record;
  herd record;
  rec record;
  daysPerMonth integer[];
  months double precision[];
  i integer;
  
begin
  select into planrec id, year, start_date, end_date from plan a where id=planid;

--raise notice 'planrec: %', planrec;

  daysPerMonth := daysAvailableByMonth(planrec.year, planrec.start_date, planrec.end_date);

--raise notice 'daysPerMonth: %', daysPerMonth;

  for herd in select id, a.name from herds a where plan=planid order by id loop

    for i in 1 .. 12 loop
      months[i] := 0.0;
    end loop;

    for rec in select id, qty*sau as sau, daysAvailableByMonth(planrec.year, arrival, est_ship) as days
               from animals a where a.herdid=herd.id loop

--raise notice 'rec: %', rec;

      for i in 1 .. 12 loop
        months[i] := months[i] + rec.days[i] * rec.sau;
      end loop;

--raise notice 'months: %', months;

    end loop;

    for i in 1 .. 12 loop
      if daysPerMonth[i] != 0 then
        months[i] := round(months[i] / daysPerMonth[i]);
      else
        months[i] := 0;
      end if;
    end loop;

--raise notice 'months: %', months;

    herdid := herd.id;
    name := herd.name;
    totsau := months;
    return next;

  end loop;

  return;
end;
$$;


--
-- Name: monthsinplan(integer); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION monthsinplan(planid integer, OUT month integer, OUT mname text) RETURNS SETOF record
    LANGUAGE plpgsql STABLE
    AS $$
declare
  rec record;
  m integer;
  ms timestamp;
  
begin
  select into rec year, start_date, end_date from plan where id=planid;
  
  for m in 1 .. 12 loop
    ms := rec.year::text||'-'||m||'-01';
    if (ms, ms+interval '1 month') overlaps (rec.start_date, rec.end_date) then
      month := m;
      mname := to_char(ms, 'Mon');
      return next;
    end if;
  end loop;

  return;
end;
$$;


--
-- Name: paddockavailability(integer); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION paddockavailability(planid integer, OUT padid integer, OUT name text, OUT omonths integer[]) RETURNS SETOF record
    LANGUAGE plpgsql STABLE
    AS $$
declare
  planrec record;
  rec record;
  lastrec record;
  pmonths integer[];
  months integer[];
  exc integer[];
  i integer;
  first boolean := true;
  
begin
  select into planrec * from plan where id=planid;

  pmonths := daysAvailableByMonth(planrec.year, planrec.start_date, planrec.end_date);
--raise notice 'pmonths: %', pmonths;

  for rec in select distinct on (b.id) a.*, b.id as ppid, b.name
           from herd_rotations c
           left outer join paddocks b on c.padid=b.id
           left outer join paddock_exclusions a on a.padid=b.id 
           where c.plan=planrec.id or a.plan is null order by b.id loop
--raise notice 'rec: %', rec;
    if first then
      lastrec := rec;
      first := false;
      months := pmonths;
    elsif lastrec.ppid != rec.ppid then
      padid := lastrec.ppid;
      name := lastrec.name;
      lastrec := rec;
      omonths := months;
      months := pmonths;
      return next;
    end if;
    if rec.exc_start is not null then
      exc := daysAvailableByMonth(planrec.year, rec.exc_start, rec.exc_end);
--raise notice 'exc: %', exc;
      for i in 1 .. 12 loop
        months[i] := case when months[i] > exc[i] then months[i] - exc[i] else 0 end;
      end loop;
    end if;
  end loop;

  if not first then
    padid := lastrec.ppid;
    name := lastrec.name;
    omonths := months;
    return next;
  end if;

  return;
end;
$$;


--
-- Name: paddockplanningdata(integer, integer[]); Type: FUNCTION; Schema: data; Owner: -
--

CREATE FUNCTION paddockplanningdata(planid integer, pids integer[] DEFAULT NULL::integer[]) RETURNS TABLE(seq integer, rid integer, padid integer, name text, grazing_days integer, cum_days integer, start_date date, end_date date, locked boolean, actmingd integer, actmaxgd integer, exc_start date, exc_end date)
    LANGUAGE sql STABLE ROWS 100
    AS $$

     select seq::integer, c.rid::integer, c.padid::integer, c.name::text, c.grazing_days::integer, c.cum_days::integer,
           (d.start_date + (c.cum_days-c.grazing_days) * interval '1 day')::date as start_date,
           (d.start_date + c.cum_days * interval '1 day')::date as end_date,
           c.act_start is not null as locked, c.actmingd::integer, c.actmaxgd::integer, c.exc_start::date, c.exc_end::date
      from (
            select a.seq, a.rid, a.padid, a.act_start, a.act_end, b.name, actmingd, actmaxgd,
                   e.exc_start, e.exc_end,
                   coalesce(grazing_days, case when defgd=1 then actmingd else actmaxgd end) as grazing_days,
                   sum(coalesce(grazing_days, case when defgd=1 then actmingd else actmaxgd end)) over (order by seq) as cum_days
              from ( select * from getOrderPaddocks(planid, pids) ) a
                   join ( select id, defgd from plan where id=planid ) bb on bb.id=planid
                   join ( select rid, name, actmingd, actmaxgd from actGrazingDays(planid)) b on a.rid=b.rid
                   left outer join paddock_exclusions e on a.padid=e.padid and e.plan=planid
             order by seq
           ) c,
           (select start_date from plan where id=planid) d;
$$;


SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: animals; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE animals (
    id integer NOT NULL,
    herdid integer NOT NULL,
    type text NOT NULL,
    qty integer,
    weight double precision,
    forage double precision,
    tag text,
    sau double precision,
    arrival timestamp without time zone,
    est_ship timestamp without time zone,
    act_ship timestamp without time zone,
    notes text
);


--
-- Name: animals_id_seq; Type: SEQUENCE; Schema: data; Owner: -
--

CREATE SEQUENCE animals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: animals_id_seq; Type: SEQUENCE OWNED BY; Schema: data; Owner: -
--

ALTER SEQUENCE animals_id_seq OWNED BY animals.id;


--
-- Name: bugs; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE bugs (
    id integer NOT NULL,
    data text
);


--
-- Name: calendar; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE calendar (
    id integer NOT NULL,
    title text NOT NULL,
    start timestamp without time zone,
    "end" timestamp without time zone,
    classname text,
    allday boolean,
    description text,
    refid text
);


--
-- Name: calendar_id_seq; Type: SEQUENCE; Schema: data; Owner: -
--

CREATE SEQUENCE calendar_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: calendar_id_seq; Type: SEQUENCE OWNED BY; Schema: data; Owner: -
--

ALTER SEQUENCE calendar_id_seq OWNED BY calendar.id;


--
-- Name: herd_rotations; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE herd_rotations (
    id integer NOT NULL,
    plan integer NOT NULL,
    padid integer NOT NULL,
    plan_quality double precision,
    herdid integer,
    plan_start timestamp without time zone,
    plan_end timestamp without time zone,
    act_start timestamp without time zone,
    act_end timestamp without time zone,
    act_forage_taken character(1),
    act_growth_rate character(1),
    act_error boolean,
    notes text,
    grazing_days integer
);


--
-- Name: COLUMN herd_rotations.plan; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.plan IS 'plan id';


--
-- Name: COLUMN herd_rotations.padid; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.padid IS 'paddock id';


--
-- Name: COLUMN herd_rotations.plan_quality; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.plan_quality IS 'estimated paddock quality in ADA/H';


--
-- Name: COLUMN herd_rotations.herdid; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.herdid IS 'herd id';


--
-- Name: COLUMN herd_rotations.plan_start; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.plan_start IS 'planned start of rotation date';


--
-- Name: COLUMN herd_rotations.plan_end; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.plan_end IS 'planned end of rotation date';


--
-- Name: COLUMN herd_rotations.act_start; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.act_start IS 'actual start of rotation date';


--
-- Name: COLUMN herd_rotations.act_end; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.act_end IS 'actual end of rotation date';


--
-- Name: COLUMN herd_rotations.act_forage_taken; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.act_forage_taken IS 'estimate of actual forage taken from paddock - H|M|L Heavy, Medium, Light';


--
-- Name: COLUMN herd_rotations.act_growth_rate; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.act_growth_rate IS 'estimate of actual growth rate of paddock during grazing period - F|S|N Fast, Slow, or None';


--
-- Name: COLUMN herd_rotations.act_error; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.act_error IS 'Was there a serious grazing error';


--
-- Name: COLUMN herd_rotations.notes; Type: COMMENT; Schema: data; Owner: -
--

COMMENT ON COLUMN herd_rotations.notes IS 'text notes about this rotation';


--
-- Name: herd_rotations_id_seq; Type: SEQUENCE; Schema: data; Owner: -
--

CREATE SEQUENCE herd_rotations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: herd_rotations_id_seq; Type: SEQUENCE OWNED BY; Schema: data; Owner: -
--

ALTER SEQUENCE herd_rotations_id_seq OWNED BY herd_rotations.id;


--
-- Name: herds; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE herds (
    id integer NOT NULL,
    name text NOT NULL,
    plan integer NOT NULL
);


--
-- Name: herds_id_seq; Type: SEQUENCE; Schema: data; Owner: -
--

CREATE SEQUENCE herds_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: herds_id_seq; Type: SEQUENCE OWNED BY; Schema: data; Owner: -
--

ALTER SEQUENCE herds_id_seq OWNED BY herds.id;


--
-- Name: monitor; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE monitor (
    id integer NOT NULL,
    padid integer NOT NULL,
    mdate timestamp without time zone NOT NULL,
    moisture integer DEFAULT (-1) NOT NULL,
    growth integer DEFAULT (-1) NOT NULL,
    ada real DEFAULT (-1.0) NOT NULL,
    who text,
    notes text
);


--
-- Name: monitor_id_seq; Type: SEQUENCE; Schema: data; Owner: -
--

CREATE SEQUENCE monitor_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: monitor_id_seq; Type: SEQUENCE OWNED BY; Schema: data; Owner: -
--

ALTER SEQUENCE monitor_id_seq OWNED BY monitor.id;


--
-- Name: paddock_exclusions; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE paddock_exclusions (
    id integer NOT NULL,
    plan integer NOT NULL,
    padid integer NOT NULL,
    exc_start timestamp without time zone,
    exc_end timestamp without time zone,
    reason text,
    exc_type text NOT NULL
);


--
-- Name: paddock_exclusions_id_seq; Type: SEQUENCE; Schema: data; Owner: -
--

CREATE SEQUENCE paddock_exclusions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: paddock_exclusions_id_seq; Type: SEQUENCE OWNED BY; Schema: data; Owner: -
--

ALTER SEQUENCE paddock_exclusions_id_seq OWNED BY paddock_exclusions.id;


--
-- Name: paddock_geom; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE paddock_geom (
    id integer NOT NULL,
    geom public.geometry(MultiPolygon,4326)
);


--
-- Name: paddocks; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE paddocks (
    id integer NOT NULL,
    name text NOT NULL,
    area double precision,
    atype text,
    crop text,
    description text
);


--
-- Name: paddocks_id_seq; Type: SEQUENCE; Schema: data; Owner: -
--

CREATE SEQUENCE paddocks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: paddocks_id_seq; Type: SEQUENCE OWNED BY; Schema: data; Owner: -
--

ALTER SEQUENCE paddocks_id_seq OWNED BY paddocks.id;


--
-- Name: plan; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE plan (
    id integer NOT NULL,
    name text NOT NULL,
    year integer NOT NULL,
    ptype integer NOT NULL,
    start_date timestamp without time zone NOT NULL,
    end_date timestamp without time zone NOT NULL,
    factors text,
    steps integer[] DEFAULT ARRAY[0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    rotations integer[],
    defgd integer DEFAULT 2
);


--
-- Name: plan_id_seq; Type: SEQUENCE; Schema: data; Owner: -
--

CREATE SEQUENCE plan_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: plan_id_seq; Type: SEQUENCE OWNED BY; Schema: data; Owner: -
--

ALTER SEQUENCE plan_id_seq OWNED BY plan.id;


--
-- Name: plan_paddock; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE plan_paddock (
    pid integer NOT NULL,
    pad integer NOT NULL,
    qual real DEFAULT 0.0 NOT NULL
);


--
-- Name: plan_recovery; Type: TABLE; Schema: data; Owner: -; Tablespace: 
--

CREATE TABLE plan_recovery (
    plan integer NOT NULL,
    month integer NOT NULL,
    minrecov integer DEFAULT 0 NOT NULL,
    maxrecov integer DEFAULT 0 NOT NULL
);


--
-- Name: id; Type: DEFAULT; Schema: data; Owner: -
--

ALTER TABLE ONLY animals ALTER COLUMN id SET DEFAULT nextval('animals_id_seq'::regclass);


--
-- Name: id; Type: DEFAULT; Schema: data; Owner: -
--

ALTER TABLE ONLY calendar ALTER COLUMN id SET DEFAULT nextval('calendar_id_seq'::regclass);


--
-- Name: id; Type: DEFAULT; Schema: data; Owner: -
--

ALTER TABLE ONLY herd_rotations ALTER COLUMN id SET DEFAULT nextval('herd_rotations_id_seq'::regclass);


--
-- Name: id; Type: DEFAULT; Schema: data; Owner: -
--

ALTER TABLE ONLY herds ALTER COLUMN id SET DEFAULT nextval('herds_id_seq'::regclass);


--
-- Name: id; Type: DEFAULT; Schema: data; Owner: -
--

ALTER TABLE ONLY monitor ALTER COLUMN id SET DEFAULT nextval('monitor_id_seq'::regclass);


--
-- Name: id; Type: DEFAULT; Schema: data; Owner: -
--

ALTER TABLE ONLY paddock_exclusions ALTER COLUMN id SET DEFAULT nextval('paddock_exclusions_id_seq'::regclass);


--
-- Name: id; Type: DEFAULT; Schema: data; Owner: -
--

ALTER TABLE ONLY paddocks ALTER COLUMN id SET DEFAULT nextval('paddocks_id_seq'::regclass);


--
-- Name: id; Type: DEFAULT; Schema: data; Owner: -
--

ALTER TABLE ONLY plan ALTER COLUMN id SET DEFAULT nextval('plan_id_seq'::regclass);


--
-- Name: animals_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY animals
    ADD CONSTRAINT animals_pkey PRIMARY KEY (id);


--
-- Name: bugs_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY bugs
    ADD CONSTRAINT bugs_pkey PRIMARY KEY (id);


--
-- Name: calendar_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY calendar
    ADD CONSTRAINT calendar_pkey PRIMARY KEY (id);


--
-- Name: herd_rotations_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY herd_rotations
    ADD CONSTRAINT herd_rotations_pkey PRIMARY KEY (id);


--
-- Name: herds_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY herds
    ADD CONSTRAINT herds_pkey PRIMARY KEY (id);


--
-- Name: monitor_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY monitor
    ADD CONSTRAINT monitor_pkey PRIMARY KEY (id);


--
-- Name: paddock_exclusions_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY paddock_exclusions
    ADD CONSTRAINT paddock_exclusions_pkey PRIMARY KEY (id);


--
-- Name: paddock_geom_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY paddock_geom
    ADD CONSTRAINT paddock_geom_pkey PRIMARY KEY (id);


--
-- Name: paddocks_pkey1; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY paddocks
    ADD CONSTRAINT paddocks_pkey1 PRIMARY KEY (id);


--
-- Name: plan_paddock_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY plan_paddock
    ADD CONSTRAINT plan_paddock_pkey PRIMARY KEY (pid, pad);


--
-- Name: plan_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY plan
    ADD CONSTRAINT plan_pkey PRIMARY KEY (id);


--
-- Name: plan_recovery_pkey; Type: CONSTRAINT; Schema: data; Owner: -; Tablespace: 
--

ALTER TABLE ONLY plan_recovery
    ADD CONSTRAINT plan_recovery_pkey PRIMARY KEY (plan, month);


--
-- PostgreSQL database dump complete
--

