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
-- Data for Name: animals; Type: TABLE DATA; Schema: data; Owner: -
--

COPY animals (id, herdid, type, qty, weight, forage, tag, sau, arrival, est_ship, act_ship, notes) FROM stdin;
20	5	Sheep, weaned, slaughter or replacement stock	50	300	3.29999999999999982	\N	0.299999999999999989	2015-03-30 00:00:00	2015-09-30 00:00:00	\N	\N
22	8	Beef cattle, growing and finishing, slaughter stock	500	800	2.29999999999999982	\N	0.800000000000000044	2015-05-21 00:00:00	2015-10-11 00:00:00	\N	\N
12	5	Beef cattle, lactating	30	1000	2.25	\N	1	2015-04-15 00:00:00	2015-09-01 00:00:00	\N	Double Diamond Cattle
23	9	Beef cattle, growing and finishing, slaughter stock	500	1000	2.29999999999999982	\N	1	2014-03-01 00:00:00	2014-10-30 00:00:00	\N	watch for parasites
24	9	Sheep, weaned, slaughter or replacement stock	500	250	3.5	\N	0.25	2014-01-01 00:00:00	2015-01-01 00:00:00	\N	\N
11	5	Goats, weaned, slaughter or replacement stock	100	250	2.25	\N	0.25	2015-04-15 00:00:00	2015-09-01 00:00:00	\N	Double Diamond Cattle
1	5	Dairy cows, dry (small or large breed)	100	920	1.80000000000000004	\N	0.92000000000000004	2015-04-15 00:00:00	2015-09-01 00:00:00	\N	Double Diamond Cattle
13	5	Beef cattle, growing and finishing, slaughter stock	500	1000	2.29999999999999982	\N	1	2015-03-30 00:00:00	2015-10-01 00:00:00	\N	John Musicant
16	5	Goats, weaned, slaughter or replacement stock	500	300	2.25	\N	0.299999999999999989	2015-03-30 00:00:00	2015-10-01 00:00:00	\N	John Musicant
21	5	Sheep, weaned, slaughter or replacement stock	-20	300	3.29999999999999982	\N	0.299999999999999989	2015-06-30 00:00:00	2015-09-30 00:00:00	\N	\N
19	5	Dairy steers	5	1000	2.29999999999999982	\N	1	2015-03-30 00:00:00	2015-09-01 00:00:00	\N	\N
\.


--
-- Name: animals_id_seq; Type: SEQUENCE SET; Schema: data; Owner: -
--

SELECT pg_catalog.setval('animals_id_seq', 24, true);


--
-- Data for Name: bugs; Type: TABLE DATA; Schema: data; Owner: -
--

COPY bugs (id, data) FROM stdin;
1	o plotting grazing rotations needs to support multiple herds\n\no probably work on Record Results and Actual Moves\n\no need all Help comments reviewed (some is too close to HMI text) and help text added for step 6-step 13.\n\no think about how we want to install the database and tables, probably via a php script that asks some questions and then configures things for the user.\n\no CONFIG items. Add to this list things that should be user configurable.\n  - database connection parameters\n  - animal type drop-down list\n\no add link to google calendar\n\no "Closed-End Plans" still need to be worked into all the tabs and calculations.\n\no need to think about a shapefile loader, can this be easily done on both linux and windows?\n\no I currently have the sample shape files loaded manually and that data is being used for the paddocks and the map. It would be nice to be able to show that on a map. Ideally, don't delete paddocks from top level "Paddocks" tab as that will delete them and the shape data from the database. You can safely add/removes Paddocks from the "Plan"->"Select Paddocks and Exclusions" tab.\n\no Add reports for Average Actual Recovery Period per paddock, Average Animal Days Per Paddock, % YOY increase/decrease in animal days per paddock, and average grazing days per paddock. Also this requires enter data during the monitoring and plan execution steps.\n\no We needs some way to report overgrazing and lack of adequate recovery time for paddocks, so this can be reported later on the grazing patterns tab.\n\no We need some kind of record sequence so updates can not be made if the record has been changed by another user accessing the database.\n\n* I have added a basic map to the "Reports" tab,  Yellow paddocks are in the ranch but not the selected plan and green paddocks are in the selected plan. click on the paddock to get it's name.\n\n
\.


--
-- Data for Name: calendar; Type: TABLE DATA; Schema: data; Owner: -
--

COPY calendar (id, title, start, "end", classname, allday, description, refid) FROM stdin;
39	New Years Getaway	2014-12-31 00:00:00	2015-01-06 00:00:00	evt-social	t		\N
40	Square Dancing Social	2015-04-04 17:00:00	2015-04-05 23:00:00	evt-social	t		\N
43	Family Meeting	2015-03-12 00:00:00	2015-03-14 00:00:00	evt-social	t	Family gets together to discuss this grazing season.	\N
44	Square Dance Social	2015-05-01 00:00:00	2015-05-02 00:00:00	evt-social	t		\N
45	Test Event	2015-05-12 09:00:00	2015-05-13 12:00:00	evt-social	t	Just testing.	\N
46	Big New Year's Party	2014-01-01 00:00:00	2014-01-05 00:00:00	evt-social	t	party at sallie's house	\N
47	Wedding	2014-04-09 00:00:00	2014-04-10 00:00:00	evt-social	t	Smith wedding.	\N
\.


--
-- Name: calendar_id_seq; Type: SEQUENCE SET; Schema: data; Owner: -
--

SELECT pg_catalog.setval('calendar_id_seq', 47, true);


--
-- Data for Name: herd_rotations; Type: TABLE DATA; Schema: data; Owner: -
--

COPY herd_rotations (id, plan, padid, plan_quality, herdid, plan_start, plan_end, act_start, act_end, act_forage_taken, act_growth_rate, act_error, notes, grazing_days) FROM stdin;
25	3	24	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
26	3	26	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
27	3	27	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
28	3	28	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
29	3	29	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
30	3	30	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
31	3	34	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
32	3	20	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
33	3	9	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
34	3	19	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
59	4	24	5	\N	2016-01-01 00:00:00	2016-03-23 00:00:00	\N	\N	\N	\N	\N	\N	\N
65	4	30	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
66	4	34	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
94	5	24	5	\N	2014-01-01 00:00:00	2014-01-29 00:00:00	\N	\N	\N	\N	\N	\N	\N
95	5	26	4	\N	2014-01-29 00:00:00	2014-02-05 00:00:00	\N	\N	\N	\N	\N	\N	\N
96	5	27	3	\N	2014-02-05 00:00:00	2014-02-07 00:00:00	\N	\N	\N	\N	\N	\N	\N
97	5	28	6	\N	2014-02-07 00:00:00	2014-02-09 00:00:00	\N	\N	\N	\N	\N	\N	\N
98	5	29	8	\N	2014-02-09 00:00:00	2014-02-11 00:00:00	\N	\N	\N	\N	\N	\N	\N
99	5	30	9	\N	2014-02-11 00:00:00	2014-03-03 00:00:00	\N	\N	\N	\N	\N	\N	\N
100	5	34	2	\N	2014-03-03 00:00:00	2014-03-16 00:00:00	\N	\N	\N	\N	\N	\N	\N
101	5	20	5	\N	2014-03-16 00:00:00	2014-03-24 00:00:00	\N	\N	\N	\N	\N	\N	\N
102	5	9	6	\N	2014-03-24 00:00:00	2014-04-14 00:00:00	\N	\N	\N	\N	\N	\N	\N
20	1	33	5	\N	2015-03-30 00:00:00	2015-03-31 00:00:00	\N	\N	\N	\N	\N	\N	\N
19	1	32	5	\N	2015-03-31 00:00:00	2015-04-06 00:00:00	\N	\N	\N	\N	\N	\N	\N
2	1	14	8	\N	2015-04-06 00:00:00	2015-04-08 00:00:00	\N	\N	\N	\N	\N	\N	2
12	1	19	5	\N	2015-04-08 00:00:00	2015-04-13 00:00:00	\N	\N	\N	\N	\N	\N	\N
1	1	20	5	\N	2015-04-13 00:00:00	2015-04-19 00:00:00	\N	\N	\N	\N	\N	\N	\N
22	1	22	5	\N	2015-04-19 00:00:00	2015-04-20 00:00:00	\N	\N	\N	\N	\N	\N	\N
16	1	23	5	\N	2015-04-20 00:00:00	2015-04-25 00:00:00	\N	\N	\N	\N	\N	\N	\N
21	1	21	5	\N	2015-04-25 00:00:00	2015-05-05 00:00:00	\N	\N	\N	\N	\N	\N	\N
18	1	31	5	\N	2015-05-05 00:00:00	2015-05-10 00:00:00	\N	\N	\N	\N	\N	\N	\N
5	1	24	5	\N	2015-05-10 00:00:00	2015-05-31 00:00:00	\N	\N	M	S	f		\N
10	1	30	5	\N	2015-07-04 00:00:00	2015-07-12 00:00:00	\N	\N	\N	\N	\N	\N	\N
7	1	27	5	\N	2015-07-12 00:00:00	2015-07-15 00:00:00	\N	\N	\N	\N	\N	\N	\N
9	1	29	5	\N	2015-07-15 00:00:00	2015-07-16 00:00:00	\N	\N	\N	\N	\N	\N	\N
8	1	28	5	\N	2015-07-16 00:00:00	2015-07-17 00:00:00	\N	\N	\N	\N	\N	\N	\N
14	1	5	5	\N	2015-07-17 00:00:00	2015-08-12 00:00:00	\N	\N	\N	\N	\N	\N	\N
17	1	6	5	\N	2015-08-12 00:00:00	2015-08-26 00:00:00	\N	\N	\N	\N	\N	\N	\N
15	1	3	5	\N	2015-08-28 00:00:00	2015-09-18 00:00:00	\N	\N	\N	\N	\N	\N	\N
11	1	9	5	\N	2015-09-18 00:00:00	2015-10-01 00:00:00	\N	\N	\N	\N	\N	\N	\N
6	1	26	5	\N	2015-06-28 00:00:00	2015-07-04 00:00:00	\N	\N	M	S	f		\N
13	1	25	5	\N	2015-05-31 00:00:00	2015-06-28 00:00:00	\N	\N	\N	\N	\N	\N	28
23	1	4	5	\N	2015-08-26 00:00:00	2015-08-28 00:00:00	\N	\N	\N	\N	\N	\N	\N
35	3	8	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
36	3	10	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
37	3	11	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
38	3	13	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
39	3	12	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
40	3	16	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
41	3	17	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
42	3	14	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
43	3	25	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
44	3	5	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
45	3	3	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
46	3	23	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
47	3	6	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
48	3	15	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
49	3	2	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
50	3	1	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
51	3	31	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
52	3	32	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
53	3	33	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
54	3	18	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
55	3	21	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
56	3	22	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
57	3	7	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
58	3	4	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
61	4	26	2	\N	2016-03-29 00:00:00	2016-04-18 00:00:00	\N	\N	\N	\N	\N	\N	\N
62	4	27	0	\N	2016-03-23 00:00:00	2016-03-29 00:00:00	\N	\N	\N	\N	\N	\N	\N
63	4	28	0	\N	2016-04-18 00:00:00	2016-04-19 00:00:00	\N	\N	\N	\N	\N	\N	\N
64	4	29	0	\N	2016-04-19 00:00:00	2016-04-22 00:00:00	\N	\N	\N	\N	\N	\N	\N
67	4	20	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
68	4	9	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
69	4	19	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
70	4	8	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
71	4	10	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
72	4	11	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
73	4	13	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
74	4	12	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
75	4	16	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
76	4	17	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
77	4	14	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
78	4	25	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
79	4	5	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
80	4	3	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
81	4	23	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
82	4	6	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
83	4	15	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
84	4	2	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
85	4	1	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
86	4	31	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
87	4	32	0	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
\.


--
-- Name: herd_rotations_id_seq; Type: SEQUENCE SET; Schema: data; Owner: -
--

SELECT pg_catalog.setval('herd_rotations_id_seq', 102, true);


--
-- Data for Name: herds; Type: TABLE DATA; Schema: data; Owner: -
--

COPY herds (id, name, plan) FROM stdin;
5	Double Diamond Cattle	1
9	Yearling Stockers	5
\.


--
-- Name: herds_id_seq; Type: SEQUENCE SET; Schema: data; Owner: -
--

SELECT pg_catalog.setval('herds_id_seq', 9, true);


--
-- Data for Name: monitor; Type: TABLE DATA; Schema: data; Owner: -
--

COPY monitor (id, padid, mdate, moisture, growth, ada, who, notes) FROM stdin;
1	30	2015-05-07 00:00:00	3	1	3	Steve	\N
2	30	2015-04-06 00:00:00	4	1	1	Steve	Blah blah blah
3	24	2015-03-02 00:00:00	2	0	2	Frank Aragona	\N
4	31	2015-03-10 00:00:00	2	1	2	Frank Aragona	\N
5	21	2015-05-10 00:00:00	2	1	2	Frank Aragona	\N
6	23	2015-05-10 00:00:00	3	2	5	Frank Aragona	\N
7	22	2015-03-10 00:00:00	2	1	2	Frank Aragona	be careful, drought!
8	20	2015-03-10 00:00:00	2	1	4	Frank Aragona	\N
9	14	2015-03-10 00:00:00	3	3	6	Frank Aragona	Under irrigation, soil moisture good.
10	33	2015-04-15 00:00:00	3	1	3	Frank Aragona	\N
11	32	2015-04-15 00:00:00	2	1	2	Frank Aragona	\N
12	19	2015-04-15 00:00:00	2	1	3	Frank Aragona	\N
13	9	2015-05-19 00:00:00	2	1	3	Kelly Mulville	Need rain!
\.


--
-- Name: monitor_id_seq; Type: SEQUENCE SET; Schema: data; Owner: -
--

SELECT pg_catalog.setval('monitor_id_seq', 13, true);


--
-- Data for Name: paddock_exclusions; Type: TABLE DATA; Schema: data; Owner: -
--

COPY paddock_exclusions (id, plan, padid, exc_start, exc_end, reason, exc_type) FROM stdin;
4	5	24	2014-05-01 00:00:00	2014-05-30 00:00:00	flooding	Livestock Exclusion
\.


--
-- Name: paddock_exclusions_id_seq; Type: SEQUENCE SET; Schema: data; Owner: -
--

SELECT pg_catalog.setval('paddock_exclusions_id_seq', 4, true);


--
-- Data for Name: paddock_geom; Type: TABLE DATA; Schema: data; Owner: -
--

COPY paddock_geom (id, geom) FROM stdin;
1	0106000020E6100000010000000103000000010000000400000065D400EC81525EC08631091956604240D65D501453525EC03733975655604240EF74D3C454525EC09C9C0D2ECF5F424065D400EC81525EC08631091956604240
2	0106000020E6100000010000000103000000010000000B00000065D400EC81525EC08631091956604240742F2C286B525EC0D90BE91312604240EF74D3C454525EC09C9C0D2ECF5F4240BE18B7C047525EC00060E93AB35F42406E330BA3FF515EC01A8B9690635F42409BD775DD2B525EC0D424C32D545F4240E819476078525EC00F6CDD7B685F4240694C3729F8525EC029F30EAF225F424061F794294E535EC08A0D24449A5F4240C33CCDA8CC535EC066C8FD4F5A60424065D400EC81525EC08631091956604240
3	0106000020E610000001000000010300000001000000240000006E330BA3FF515EC01A8B9690635F424098520ACCF5515EC08311EA11435F42401A110EBEF6515EC05C1BD284115F4240471E675905525EC0AA60B200D65E42406B7711CE09525EC0CD65A41FA55E4240636652F509525EC094495A2E745E4240D6E7316AE0515EC0455DF1818B5D42408FF3C329EA515EC05790B1E88C5D4240754BE7CCFD515EC01E7679F3635D4240CE8A6FEC06525EC0A2503FD5345D42409F39083C0B525EC02E6F5F14165D42404CF5A8DEF9515EC0EC3F49ECE95C4240B29C25F9E1515EC02B3525F6C35C42407FD9CDA3CD515EC0D824F004045C4240A60E4482FC515EC091A52604095C4240850636700B525EC04C922E905D5C4240DFB9CA6E1E525EC054EA35156E5C4240C1EF25211C525EC0FE1BCBC38A5C42408A60431C21525EC087C89B97A65C42408A169DE22A525EC05227BBA6B35C42406290FB5E2F525EC052C8C88EC05C424038FDB15142525EC08B309B55D55C4240B5167E284A525EC03B6F0E14DE5C4240C899367982525EC08C5399AD105D424067687DCE9A525EC0790D00171D5D4240A8D947ABAF525EC031CCD44E385D42405E7C9D2CC3525EC0ACB150AE565D424085E94FEACC525EC05B5383EE665D4240DF48EF48E8525EC0A93C02EB8B5D4240E6B43B4DF3525EC06761C776A05D42404DEBCCD2E6525EC091D29447D45D42409710D9B281525EC00FFB6F87805E4240694C3729F8525EC029F30EAF225F4240E819476078525EC00F6CDD7B685F42409BD775DD2B525EC0D424C32D545F42406E330BA3FF515EC01A8B9690635F4240
4	0106000020E6100000010000000103000000010000000A0000001D75DB1837535EC0F0C61D5F965D4240F8149B0FF7525EC0951E6C26175E4240B657ED28D2525EC033E2AD3C5A5E42406ADE8ED89F525EC05CEB81CCA95E42409710D9B281525EC00FFB6F87805E42404DEBCCD2E6525EC091D29447D45D4240E6B43B4DF3525EC06761C776A05D42408A1848B605535EC03D5C0661965D424032981F7417535EC0CFA0EAF28C5D42401D75DB1837535EC0F0C61D5F965D4240
5	0106000020E6100000010000000103000000010000000E0000001D75DB1837535EC0F0C61D5F965D424049944BC524545EC077AFB4770C5D4240ACF432B053545EC01E664EE8FB5C4240AF11835F9A545EC03C799223EF5C42401C17A0CC0E555EC0D729ADBCEC5C4240C961355047555EC047AB0743E55D42400B8FF70ECE545EC0540AF4682F5E424018F8B23596545EC0A2BB6094815E42406545D84DCE535EC033F4C3CD7F5E42405CF899C2CD535EC05CDC0DCE1C5F4240A25B4497AB535EC02C53AF1A205F424095EBD5F4AA535EC0EE51A7F2685E4240C1602443FB525EC0ECAAA0B30E5E42401D75DB1837535EC0F0C61D5F965D4240
6	0106000020E610000001000000010300000001000000080000005CF899C2CD535EC05CDC0DCE1C5F4240C33CCDA8CC535EC066C8FD4F5A604240694C3729F8525EC029F30EAF225F42406ADE8ED89F525EC05CEB81CCA95E4240C1602443FB525EC0ECAAA0B30E5E424095EBD5F4AA535EC0EE51A7F2685E4240A25B4497AB535EC02C53AF1A205F42405CF899C2CD535EC05CDC0DCE1C5F4240
7	0106000020E6100000010000000103000000010000001500000092AEDEF427535EC0AD37616D295C4240611D20E445535EC036D74347295C42401EDCD41C77535EC04F99E0EB655C424052D32D3784535EC088D66C4A655C42400AF7078C9F535EC02EFEFC1A6F5C424096E64214AA535EC0F018EE6D755C42402F07E641A9535EC03B2E9A7A875C4240A2C61CAFA4535EC0D877FB6C9A5C4240EC79CDC09A535EC0F0BE7A20965C4240FBFC2EC088535EC075FDFD958E5C424077FE511B83535EC042D7F06F915C42407C77170A78535EC00B0AB210835C42409343E44C6F535EC03237F5D1835C42406A85A29754535EC079509A057A5C4240FA5ED61342535EC09B3EAD6C685C424020F3607334535EC08A30BE00605C42402BB67C0E32535EC0A6ABE7E5565C424087A8B3E02B535EC00EA885B3515C424072065F0423535EC08E811E7F5D5C4240DF36A76B13535EC05329FA0D5E5C424092AEDEF427535EC0AD37616D295C4240
8	0106000020E61000000100000001030000000100000014000000DF36A76B13535EC05329FA0D5E5C424072065F0423535EC08E811E7F5D5C424087A8B3E02B535EC00EA885B3515C424020F3607334535EC08A30BE00605C42409343E44C6F535EC03237F5D1835C42407C77170A78535EC00B0AB210835C424077FE511B83535EC042D7F06F915C4240A2C61CAFA4535EC0D877FB6C9A5C4240AF23D35373535EC0CC298249DB5C424035C7442E55535EC01D331B82EE5C42406EB8E8B150535EC0DEA0BA6CF95C4240EECDF7F156535EC019C861D3305D4240959BB7E758535EC0DBF182C2825D42401D75DB1837535EC0F0C61D5F965D424032981F7417535EC0CFA0EAF28C5D4240E6B43B4DF3525EC06761C776A05D424085E94FEACC525EC05B5383EE665D4240FCA8C1E3E7525EC0D6C8B1AD345D4240E3A08434EC525EC0F058775DC85C4240DF36A76B13535EC05329FA0D5E5C4240
9	0106000020E6100000010000000103000000010000001C000000A60E4482FC515EC091A52604095C42407FD9CDA3CD515EC0D824F004045C424037A54DA2BD515EC0FD9C1CEA655B424097559279C4515EC04CD47568415B42406DD7F9E0F2515EC09ED625E0615B4240797A7D7723525EC00863664A665B424014F1856033525EC0D84F0675815B4240ABDC43254D525EC0F03A7859A75B4240E2B097BE64525EC0BDC2B60FB05B424019E7361189525EC0ECB0A745DA5B424098B087219F525EC005382E0BFD5B424025B1CFE292525EC04DEF6AD6205C424048052162D8525EC060FD0EB9075C42409563D12EE3525EC0A070AA2F2E5C42408E1FC058F2525EC0CCBD3DC6545C42402100AD960D535EC0C88886DD6D5C4240E3A08434EC525EC0F058775DC85C4240FCA8C1E3E7525EC0D6C8B1AD345D424085E94FEACC525EC05B5383EE665D4240A8D947ABAF525EC031CCD44E385D424067687DCE9A525EC0790D00171D5D4240C899367982525EC08C5399AD105D42406290FB5E2F525EC052C8C88EC05C42408A169DE22A525EC05227BBA6B35C42408A60431C21525EC087C89B97A65C4240DFB9CA6E1E525EC054EA35156E5C4240850636700B525EC04C922E905D5C4240A60E4482FC515EC091A52604095C4240
10	0106000020E6100000010000000103000000010000000500000048052162D8525EC060FD0EB9075C4240F1378BF6C2525EC08488D8760F5C4240A99AA8CCB8525EC0150DB522D85B4240E471FF82D8525EC03C5B5532BE5B424048052162D8525EC060FD0EB9075C4240
11	0106000020E61000000100000001030000000100000005000000BC46F627C7525EC0C6948D64CC5B4240E6D7AA77DD525EC02AB32B00945B42409F1392EAE8525EC00972FB969C5B4240E471FF82D8525EC03C5B5532BE5B4240BC46F627C7525EC0C6948D64CC5B4240
12	0106000020E61000000100000001030000000100000012000000E2B097BE64525EC0BDC2B60FB05B4240D9E0241752525EC0EA3616C59F5B424032A1C35A3B525EC09F7BC422805B4240B0306BAE2C525EC0AF774CCD6A5B42400213515B2C525EC0A0B78EF3635B42404C27567337525EC098DA6DB7525B4240139F3AFE36525EC0A9C3632E425B42400642DD582C525EC0A31F05282B5B4240B00198EC2B525EC058FC2B86175B4240D7950C3D28525EC0E5BD00F6085B4240F1D37EA81B525EC01478A8E9F25A42402433F9F619525EC045601136DE5A42404BCD5ED721525EC0811795EACD5A42401B8EE46B2E525EC0D2F3E7F6E35A42403CFAAA2448525EC03CDD0C4E175B42404C09EA0062525EC054B4CB413E5B42405FE2E07E8A525EC0D4BF99F46F5B4240E2B097BE64525EC0BDC2B60FB05B4240
13	0106000020E6100000010000000103000000010000000B0000005FE2E07E8A525EC0D4BF99F46F5B4240DB177B0C8E525EC0FA2E77EB575B42409D1EECCDAC525EC04810265A5A5B424036FBA371C8525EC03E81E19A715B4240E6D7AA77DD525EC02AB32B00945B4240581CAA21D1525EC00EEA252EB35B4240F49BAD2EB8525EC0F6BEACC3945B424046B2976DA1525EC0A9B15AAE765B42401506CD039D525EC06D8D8F4C7E5B424001FD511898525EC05534D539885B42405FE2E07E8A525EC0D4BF99F46F5B4240
14	0106000020E6100000010000000103000000010000000A0000009D1EECCDAC525EC04810265A5A5B42400DFB2468D9525EC0BB455DF50E5B4240B94E0C17F8525EC0BEA296143F5B42407D58A9BEED525EC0DF89753E555B4240AE881EC7E5525EC025818ACC6D5B4240B431AC72F1525EC0CCBF02E38D5B42409F1392EAE8525EC00972FB969C5B4240E6D7AA77DD525EC02AB32B00945B42408AA9230ECB525EC0BEB199E0755B42409D1EECCDAC525EC04810265A5A5B4240
15	0106000020E61000000100000001030000000100000006000000B431AC72F1525EC0CCBF02E38D5B4240AE881EC7E5525EC025818ACC6D5B42407D58A9BEED525EC0DF89753E555B424066CF359909535EC0FA5FA523595B424091DD785705535EC0C70839317E5B4240B431AC72F1525EC0CCBF02E38D5B4240
16	0106000020E61000000100000001030000000100000006000000B94E0C17F8525EC0BEA296143F5B42402319330BFF525EC061C0A2242B5B42409BAE03330E535EC0B1DFD57C405B424066CF359909535EC0FA5FA523595B42407D58A9BEED525EC0DF89753E555B4240B94E0C17F8525EC0BEA296143F5B4240
17	0106000020E610000001000000010300000001000000050000002319330BFF525EC061C0A2242B5B4240B94E0C17F8525EC0BEA296143F5B42400DFB2468D9525EC0BB455DF50E5B42405E5B5003F5525EC0DB5C1B4AF95A42402319330BFF525EC061C0A2242B5B4240
18	0106000020E6100000010000000103000000010000000C0000005E5B5003F5525EC0DB5C1B4AF95A424075890D5501535EC0424F1489DE5A42404535BDF208535EC043C843B9E55A4240D5C79A5E11535EC0C9D23981CF5A4240544D5C201A535EC0AE212576C65A42400785F57529535EC0418FF58BCB5A4240A196A1C828535EC0B1CA1F57DD5A4240915374F128535EC0C4F64A25265B424082EF8F7714535EC0AF52331E3F5B42409BAE03330E535EC0B1DFD57C405B42402319330BFF525EC061C0A2242B5B42405E5B5003F5525EC0DB5C1B4AF95A4240
19	0106000020E610000001000000010300000001000000130000005E5B5003F5525EC0DB5C1B4AF95A42400DFB2468D9525EC0BB455DF50E5B4240F160BE329F525EC0B9953C108E5A4240FC2C9D6E80525EC019383A3B7E5A42400BED0ECF62525EC028FDC874075A42401F68B5D77F525EC07A1BE757F9594240E1A207A495525EC0CDFFC6AEEC594240CE3472F0C7525EC059DB9C0E025A42404D32D2BEE2525EC0CEEE4ED3025A4240741529EBF4525EC09CDB7E39265A424013C27C621D535EC0CE9A3E21105A4240B3A2C19617535EC07B4C66184A5A42402B73DEEE1C535EC0CC6E28A6965A42400785F57529535EC0418FF58BCB5A4240544D5C201A535EC0AE212576C65A4240D5C79A5E11535EC0C9D23981CF5A42404535BDF208535EC043C843B9E55A424075890D5501535EC0424F1489DE5A42405E5B5003F5525EC0DB5C1B4AF95A4240
20	0106000020E6100000010000000103000000010000001A00000013C27C621D535EC0CE9A3E21105A4240741529EBF4525EC09CDB7E39265A42404D32D2BEE2525EC0CEEE4ED3025A4240E1A207A495525EC0CDFFC6AEEC5942400BED0ECF62525EC028FDC874075A4240A092F2A452525EC06D6B61B2FB594240794358563B525EC023C75E7CD6594240D277FDE82E525EC08D20391CDB594240CD1BE39A1D525EC0990934AEC65942408214C0150E525EC00CB31A37CB5942406CA2F90E03525EC0D14D6CA9AE594240CFAD798804525EC0EB11D5D786594240D908B92408525EC065A046715859424012FEE00116525EC0785F09522F594240057EAF651F525EC0344F3470FF5842400B78837140525EC09E9F4C49E4584240AC335DAC45525EC0AD45BDC0C6584240839ACEFE75525EC0DC8A6A88B8584240A319A90B6A525EC03E447E93015942400CD74DD465525EC0B4BEE3C466594240B56DEBDB79525EC0FAF3647E9C59424074C50DBF9C525EC0C325BF71AC5942405F5B1A09CA525EC0AC603663B4594240782BC660E8525EC0F19ED567EA5942407390226D0C535EC09D855EC2EE59424013C27C621D535EC0CE9A3E21105A4240
21	0106000020E610000001000000010300000001000000130000007390226D0C535EC09D855EC2EE594240782BC660E8525EC0F19ED567EA5942405F5B1A09CA525EC0AC603663B4594240B56DEBDB79525EC0FAF3647E9C5942400CD74DD465525EC0B4BEE3C466594240839ACEFE75525EC0DC8A6A88B8584240217310A9E5525EC0BB6C499DFC5842405FBE909D32535EC0DB5223B6BE584240A989809361535EC0F439A4A1B5584240EB289496DA535EC087D259D00B594240B733D3FDCA535EC0A6397F7547594240EE88E5AD7A535EC062132FE199594240A8DA36C772535EC07306F6187A5942406D42AACB65535EC00DEDE08653594240530B9E673A535EC07A5CD9975A594240D13C99671E535EC0014DBDBD685942408D11E0FA08535EC089C12B35B1594240C2FAD0C910535EC0EDADDD4AD95942407390226D0C535EC09D855EC2EE594240
22	0106000020E61000000100000001030000000100000006000000839ACEFE75525EC0DC8A6A88B8584240AC335DAC45525EC0AD45BDC0C6584240BEE8084061525EC0A8BBDF020E584240A9C82E827F525EC023D07FE110584240A03879CE7D525EC0C5D5FBD24F584240839ACEFE75525EC0DC8A6A88B8584240
23	0106000020E6100000010000000103000000010000000E000000C3AAD44C5F525EC00F6D61691D574240118DCE4B83525EC097C15F4F045742404DCB97188A525EC0B63424D3F1564240EF912273A7525EC0948030C3EF5642402AD6F29020535EC04B6C309BD45742408B0438C420535EC0D158597EFD5742401FF015B62F535EC0EC9E727C06584240ADBE8E5F3B535EC09595B7E1395842409A6824B00A535EC0DB16985F4E584240D6EC871DE2525EC04FC360C04B5842404B815EABD3525EC0EB0D603114584240DC057BD4C1525EC0DFA7ABD8C55742403F1B911089525EC0692AF7DE73574240C3AAD44C5F525EC00F6D61691D574240
24	0106000020E61000000100000001030000000100000017000000EE88E5AD7A535EC062132FE1995942404ED47E7406545EC05AD324BA305A42401CCEC6C8CF535EC0ADA6BA55C85A4240BA75DDB8B0535EC08CB2B061315B42404B6FB1FDD8535EC06D312C44645B4240F5801DE018545EC089A5DC96685B42405F3CD73E64545EC0B04AD9BC565C4240AF11835F9A545EC03C799223EF5C424049944BC524545EC077AFB4770C5D4240959BB7E758535EC0DBF182C2825D42407B4C12567C535EC0B8C6957A495D4240F5482B28AA535EC0FFD5CA8B135D4240E27C1767D9535EC02DD810B4E95C4240F152D795DA535EC05027C6C57D5C42405F3BDBF1AC535EC066894E88155C424035601FF17B535EC0EA26A9CAC35B4240752071324C535EC0A58800B48D5B42402AE4946048535EC052065FC23F5B4240797C21AD55535EC07F5094D0F45A424053CB59E04C535EC0A4DAFA58C25A4240CBD600C158535EC0E212F2CE6A5A42406161CA0779535EC05D5CE34B075A4240EE88E5AD7A535EC062132FE199594240
25	0106000020E61000000100000001030000000100000013000000EE88E5AD7A535EC062132FE199594240B733D3FDCA535EC0A6397F7547594240EB289496DA535EC087D259D00B594240FA5FC929F9535EC0FA2C6FFF0C5942403552F3B65E545EC003612C8E7D5942405346C7769B545EC0D0743339325A4240C30497F0B5545EC0AD485327505A42405062B55FD9545EC0E63213C26D5A4240BB41C86BA5545EC041DD3E18C65A424021A8ABD472545EC0E2F1C8F2055B4240EE00C780B7545EC0003C13788F5B42405BB9D81020555EC0E4D2697B175C424080295BFB7E545EC0A09ADF04A25C4240F5801DE018545EC089A5DC96685B42404B6FB1FDD8535EC06D312C44645B4240BA75DDB8B0535EC08CB2B061315B42401CCEC6C8CF535EC0ADA6BA55C85A42404ED47E7406545EC05AD324BA305A4240EE88E5AD7A535EC062132FE199594240
26	0106000020E610000001000000010300000001000000090000005062B55FD9545EC0E63213C26D5A424076DA19B33F555EC0A63D083DC35A424093451D30F2545EC07CB57438345B4240A9AF79520D555EC0CE18BA598A5B42400994C69435555EC085E1A096A45B42405BB9D81020555EC0E4D2697B175C4240E53768CDBF545EC0DFA4FC439A5B424021A8ABD472545EC0E2F1C8F2055B42405062B55FD9545EC0E63213C26D5A4240
27	0106000020E6100000010000000103000000010000000800000076DA19B33F555EC0A63D083DC35A424092DF661885555EC0A4F5B855F85A4240EA57FF3B67555EC078EB1DF32A5B42402805A04A49555EC0100DA93D485B42400994C69435555EC085E1A096A45B4240A9AF79520D555EC0CE18BA598A5B424093451D30F2545EC07CB57438345B424076DA19B33F555EC0A63D083DC35A4240
28	0106000020E610000001000000010300000001000000080000000994C69435555EC085E1A096A45B42402805A04A49555EC0100DA93D485B4240EA57FF3B67555EC078EB1DF32A5B4240E37154417B555EC09AB0701B425B42405F84569B7F555EC04D36D15B605B4240B12C5ABF6F555EC0DAEFEC05795B42405E0BC95051555EC023B6D77EC35B42400994C69435555EC085E1A096A45B4240
29	0106000020E61000000100000001030000000100000007000000EA57FF3B67555EC078EB1DF32A5B424092DF661885555EC0A4F5B855F85A42400D65A609AA555EC017D8E7DF105B4240EFE5C33EC2555EC0BFA972D5335B4240E0B176D69E555EC0130BBE355C5B4240E37154417B555EC09AB0701B425B4240EA57FF3B67555EC078EB1DF32A5B4240
30	0106000020E6100000010000000103000000010000000C000000EFE5C33EC2555EC0BFA972D5335B42400A3E866A21565EC030AB5F84CF5B424088CD80A3E2555EC09347DA8A385C424057FB2DDF80555EC07F2BA0C19C5C42405BB9D81020555EC0E4D2697B175C42400994C69435555EC085E1A096A45B42405E0BC95051555EC023B6D77EC35B4240B12C5ABF6F555EC0DAEFEC05795B42405F84569B7F555EC04D36D15B605B4240E37154417B555EC09AB0701B425B4240E0B176D69E555EC0130BBE355C5B4240EFE5C33EC2555EC0BFA972D5335B4240
31	0106000020E610000001000000010300000001000000100000000785F57529535EC0418FF58BCB5A42402B73DEEE1C535EC0CC6E28A6965A4240B3A2C19617535EC07B4C66184A5A424013C27C621D535EC0CE9A3E21105A42407390226D0C535EC09D855EC2EE594240C2FAD0C910535EC0EDADDD4AD95942408D11E0FA08535EC089C12B35B1594240D13C99671E535EC0014DBDBD68594240530B9E673A535EC07A5CD9975A5942406D42AACB65535EC00DEDE08653594240EE88E5AD7A535EC062132FE1995942406161CA0779535EC05D5CE34B075A4240CBD600C158535EC0E212F2CE6A5A424027CE50EF4F535EC01F5B95CEAB5A424061D37C833B535EC0CBA242D1C95A42400785F57529535EC0418FF58BCB5A4240
32	0106000020E61000000100000001030000000100000015000000171872D428535EC02311BB68F25A424053CB59E04C535EC0A4DAFA58C25A4240797C21AD55535EC07F5094D0F45A42402AE4946048535EC052065FC23F5B4240752071324C535EC0A58800B48D5B424035601FF17B535EC0EA26A9CAC35B42405F3BDBF1AC535EC066894E88155C4240F152D795DA535EC05027C6C57D5C4240E27C1767D9535EC02DD810B4E95C4240F5482B28AA535EC0FFD5CA8B135D42400E9A4A0F7E535EC09FB3152FCD5C4240A2C61CAFA4535EC0D877FB6C9A5C424096E64214AA535EC0F018EE6D755C42401EDCD41C77535EC04F99E0EB655C4240611D20E445535EC036D74347295C424092AEDEF427535EC0AD37616D295C42404D1C17711B535EC037091286945B4240AF1897D50E535EC0D336DA25845B42409BAE03330E535EC0B1DFD57C405B4240915374F128535EC0C4F64A25265B4240171872D428535EC02311BB68F25A4240
33	0106000020E61000000100000001030000000100000007000000F5482B28AA535EC0FFD5CA8B135D4240959BB7E758535EC0DBF182C2825D4240EECDF7F156535EC019C861D3305D42406EB8E8B150535EC0DEA0BA6CF95C42400E9A4A0F7E535EC09FB3152FCD5C4240B1B4E4AD93535EC0E1FD5DAEEF5C4240F5482B28AA535EC0FFD5CA8B135D4240
34	0106000020E6100000010000000103000000010000000C000000C961355047555EC047AB0743E55D42401C17A0CC0E555EC0D729ADBCEC5C4240AF11835F9A545EC03C799223EF5C424080295BFB7E545EC0A09ADF04A25C42405BB9D81020555EC0E4D2697B175C424057FB2DDF80555EC07F2BA0C19C5C424088CD80A3E2555EC09347DA8A385C42400A3E866A21565EC030AB5F84CF5B4240D98753FCC5565EC03F2093EBA05C4240E33B0A15D7565EC04426B374DF5C424006A9533ED8565EC0BBB731C1005D4240C961355047555EC047AB0743E55D4240
\.


--
-- Data for Name: paddocks; Type: TABLE DATA; Schema: data; Owner: -
--

COPY paddocks (id, name, area, atype, crop, description) FROM stdin;
1	Rifle Range Corrals	14.3316691036711124	geometry	\N	\N
2	Rifle Range	359.602500625587254	geometry	\N	\N
3	Lake Field	614.377062704224045	geometry	\N	\N
4	Wire Corral Field	65.5493151204693447	geometry	\N	\N
5	Island Field	765.204462885355952	geometry	\N	\N
6	North Field	396.08531267511006	geometry	\N	\N
7	Trap	30.3141083579764263	geometry	\N	\N
8	Holding Field #1	169.773633472227829	geometry	\N	\N
9	Front Field	380.508379981158271	geometry	\N	\N
10	Holding Field #2	8.1940656378040071	geometry	\N	\N
11	Holding Field #3	2.70915537512542626	geometry	\N	\N
12	Horse Pasture	43.1567583390396408	geometry	\N	\N
13	Holding Field #4	15.3322459194572502	geometry	\N	\N
14	Irrigated Pasture	23.4684963725246831	geometry	\N	\N
15	Pond	6.33212545301193597	geometry	\N	\N
16	Hospital #1	3.78619307004605643	geometry	\N	\N
17	Hospital #2	5.79439599073958522	geometry	\N	\N
18	Shipping Corrals Field	20.5433042434264621	geometry	\N	\N
19	Hill Field	151.705636675012045	geometry	\N	\N
20	Flooded 60	163.08545380983594	geometry	\N	\N
21	Thousand Trails	299.656083570741032	geometry	\N	\N
22	Thousand Trails #2	31.8288030776562394	geometry	\N	\N
23	Mexican Flat	139.518298937255508	geometry	\N	\N
24	100 Field	605.304199146692099	geometry	\N	\N
25	Irvin's Field	705.906642164056848	geometry	\N	\N
26	Cienega Holding #1	185.186203303869576	geometry	\N	\N
27	Cienega Holding #2	72.8797505561801984	geometry	\N	\N
28	Cienega Holding #3	27.5080683073924597	geometry	\N	\N
29	Cienega Holding #4	22.492112950434997	geometry	\N	\N
30	Cienega Holding #5	235.49481932054826	geometry	\N	\N
31	River #1	135.853796080153757	geometry	\N	\N
32	River #2	189.007504837274411	geometry	\N	\N
33	River #3	38.3430363620981538	geometry	\N	\N
34	Deer Camp	707.918234517727569	geometry	\N	\N
\.


--
-- Name: paddocks_id_seq; Type: SEQUENCE SET; Schema: data; Owner: -
--

SELECT pg_catalog.setval('paddocks_id_seq', 1, true);


--
-- Data for Name: plan; Type: TABLE DATA; Schema: data; Owner: -
--

COPY plan (id, name, year, ptype, start_date, end_date, factors, steps, rotations, defgd) FROM stdin;
1	Y2015	2015	0	2015-03-30 00:00:00	2015-09-30 00:00:00	* paddock A needs river bank repairs from flooding\n* paddock G needs goats to deal with weeds\n* avoid paddock E during hunting season\n* Flies in rifle Range during summer months	{0,0,0,0,0,0,0,0,0,0,0,0,0,0}	{5,2,6,7,8,9,10,11,12,3,13,14,15,16,17,18,19,1,20,21,22,23}	1
3	2015b	2015	0	2015-05-20 00:00:00	2015-10-01 00:00:00	none	{0,0,0,0,0,0,0,0,0,0,0,0,0,0}	\N	2
4	2016	2016	0	2016-01-01 00:00:00	2017-01-01 00:00:00	\N	{0,0,0,0,0,0,0,0,0,0,0,0,0,0}	\N	1
5	2014	2014	0	2014-01-01 00:00:00	2015-01-01 00:00:00	*We want more wildlife on the ranch\n*We would like to improve our soil moisture management.	{1,1,1,1,1,1,1,1,1,0,0,0,0,0}	\N	1
\.


--
-- Name: plan_id_seq; Type: SEQUENCE SET; Schema: data; Owner: -
--

SELECT pg_catalog.setval('plan_id_seq', 5, true);


--
-- Data for Name: plan_paddock; Type: TABLE DATA; Schema: data; Owner: -
--

COPY plan_paddock (pid, pad, qual) FROM stdin;
1	24	5
1	26	5
1	27	5
1	28	5
1	29	5
1	30	5
1	20	5
1	9	5
1	19	5
1	14	5
1	25	5
1	5	5
1	3	5
1	23	5
1	6	5
1	31	5
1	32	5
1	33	5
1	21	5
1	22	5
1	4	5
\.


--
-- Data for Name: plan_recovery; Type: TABLE DATA; Schema: data; Owner: -
--

COPY plan_recovery (plan, month, minrecov, maxrecov) FROM stdin;
1	3	172	184
1	4	172	184
1	5	172	184
1	6	172	184
1	7	172	184
1	8	172	184
1	9	172	184
4	1	90	120
4	2	90	120
4	3	90	120
4	4	90	120
4	5	90	120
4	6	90	120
4	7	90	120
4	8	90	120
4	9	90	120
4	10	90	120
4	11	90	120
4	12	90	120
5	1	90	120
5	2	90	120
5	3	90	120
5	4	90	120
5	5	90	120
5	6	90	120
5	7	90	120
5	8	90	120
5	9	90	120
5	10	90	120
5	11	90	120
5	12	90	120
\.


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

